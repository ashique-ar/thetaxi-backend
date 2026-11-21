<?php

namespace App\Services;

use App\Models\Finance\FinancialAuditEvent;
use App\Models\Booking\BookingStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingHistoryService
{
    private const TRACKED_FIELDS = [
        'status', 'old_status', 'new_status', 'payment_status', 'payment_collection_status',
        'booking_channel_id', 'booking_source', 'booking_origin', 'booking_date', 'booked_at', 'created_from',
        'workflow_step', 'workflow_data', 'confirmation_number', 'approval_priority', 'approval_requested_at', 'approval_requested_by',
        'customer_id', 'agent_id', 'responsible_user_id', 'passenger_count', 'luggage_count', 'driver_hire_settlement_id', 'issued_date', 'issued_by',
        'special_requirements', 'emergency_contact_name', 'emergency_contact_phone',
        'review_notes', 'base_amount', 'total_estimated', 'total_actual', 'driver_cost',
        'distance_cost', 'addons_cost',
        'is_corporate_booking', 'corporate_account_id',
        'corporate_department_id', 'corporate_division_id', 'employee_id',
        'cost_center', 'project_code', 'service_type_id', 'vehicle_group_id',
        'vehicle_class_id', 'vehicle_id', 'driver_id', 'from_date', 'from_time',
        'to_date', 'to_time', 'pickup_landmark', 'dropoff_landmark',
        'item_type', 'item_code', 'duration_days', 'duration_hours', 'duration_minutes', 'is_self_driven',
        'pickup_location', 'pickup_latitude', 'pickup_longitude', 'dropoff_location', 'dropoff_latitude', 'dropoff_longitude',
        'final_priced_at', 'price_overridden_at', 'price_overridden_by', 'price_override_amount', 'price_override_reason',
        'unit_price', 'total_price', 'currency', 'discount_amount', 'tax_amount', 'confirmed',
        'confirmed_at', 'completed_at', 'cancelled_at', 'approval_status', 'approval_justification', 'status',
        'approval_by', 'approval_at', 'approval_note',
        'requires_approval', 'approved_at', 'actual_start', 'actual_end',
        'manager_id', 'requested_at', 'processed_by', 'conditions', 'notes',
        'trip_phase', 'trip_completed_at',
        'assigned_from', 'assigned_to', 'assignment_type', 'assignment_notes',
        'confirmation_method', 'confirmation_notes', 'confirmed_by', 'confirmed_by_name_snapshot',
        'assigned_by_name_snapshot', 'driver_name_snapshot', 'decline_reason', 'parent_assignment_id',
        'pickup_arrived_at', 'trip_started_at', 'accept_latitude', 'accept_longitude', 'final_latitude', 'final_longitude',
        'pickup_arrival_latitude', 'pickup_arrival_longitude', 'trip_start_latitude', 'trip_start_longitude',
        'hire_waiting_time_seconds', 'pickup_waiting_time_seconds', 'total_waiting_time_seconds',
        'amount', 'refunded_amount', 'payment_method', 'payment_stage',
        'payment_purpose', 'finality_status', 'allocation_status', 'refund_method',
        'driver_company_settlement_status', 'driver_company_settled_at',
        'payments_total', 'refunds_total', 'adjustments_total', 'outstanding_total', 'settled_at',
        'refunded_at', 'source_amount', 'source_currency', 'lkr_amount',
        'allocated_amount', 'driver_company_settled_amount', 'fx_rate_to_lkr', 'fx_rate_at',
        'fx_source', 'reference', 'received_via', 'received_at', 'received_by',
        'commission_amount', 'receipt_amount', 'eligible_amount', 'commission_rate',
        'discount_amount', 'original_amount', 'final_amount', 'discount_name', 'discount_type',
        'approval_status', 'is_active', 'qc_status', 'inspection_completed_at',
        'interior_condition', 'exterior_condition', 'mechanical_condition', 'cleanliness_rating',
        'damages_found', 'booking_qc_id', 'interval_km', 'interval_days', 'next_due_date', 'next_due_mileage',
        'actual_start_time', 'actual_return_time', 'distance_km', 'waiting_minutes',
        'calculated_amount', 'applied_rate', 'rate_type', 'hours_calculated', 'days_calculated',
        'minimum_charge_applied', 'includes_fuel', 'includes_driver', 'accepted_at',
        'log_date', 'start_km', 'end_km', 'total_km', 'entry_source', 'assigned_by', 'assigned_at',
        'submitted_by', 'submitted_at', 'revision_number', 'verified_by', 'verified_at',
        'delta_source_amount', 'delta_lkr_amount', 'previous_effective_source_amount', 'previous_effective_lkr_amount',
        'resulting_effective_source_amount', 'resulting_effective_lkr_amount', 'counts_as_new_sales_adjustment',
        'additional_charge', 'charge_currency', 'requested_end_at', 'current_end_at',
        'status', 'formula_kind', 'commission_amount_lkr', 'eligible_lkr_amount', 'hold_code',
        'receipt_finality_status', 'decision_at', 'secured_at', 'contract_value_source',
        'contract_value_lkr', 'new_customer_status', 'commission_category', 'business_classification',
        'invoice_number', 'subtotal', 'total_amount', 'issue_date', 'due_date',
        'discount_name', 'type', 'value', 'adjustment_type', 'applied_amount',
        'event_type', 'source', 'message_direction', 'outbound_status',
        'stop_type', 'route_order', 'completed_at', 'completed_action', 'skip_reason',
        'type_sequence', 'arrived_at', 'address', 'location', 'latitude', 'longitude',
        'arrived_latitude', 'arrived_longitude', 'completed_latitude', 'completed_longitude',
        'assignment_id', 'start_time', 'end_time', 'total_distance_km', 'linked_booking_id',
        'call_status', 'follow_up_due_at', 'follow_up_completed_at',
        'exception_type', 'details', 'resolved_by', 'resolved_at', 'detected_at',
        'booking_collection_work_item_id', 'recipient_user_id', 'reminder_type', 'channel',
        'schedule_due_date', 'outstanding_amount', 'source_currency', 'database_notification_id', 'dispatched_at',
        'points', 'balance_before', 'balance_after', 'amount_spent', 'earning_rate',
        'redemption_value', 'redemption_rate', 'expired_at', 'processed_at',
        'charge_amount', 'paid_before_amount', 'refund_amount', 'adjustment_amount',
        'allocated_amount', 'outstanding_amount', 'base_batta_amount', 'night_count',
        'night_batta_rate', 'night_batta_amount', 'manual_adjustment_amount', 'approved_batta_amount',
        'approved_expenses_total', 'iou_total', 'final_balance', 'cash_collected_unsettled',
        'paid_at', 'settlement_recorded_at', 'status', 'frozen_at', 'service_date',
        'activity_type', 'outcome', 'next_action', 'next_action_at', 'occurred_at',
        'included_count', 'service_date', 'direction', 'route_id', 'shift_id', 'program_id',
        'expires_at', 'invalidated_at', 'accepted_at', 'revision_number', 'amount_due',
        'revision_amount_due', 'revision_total',
        'incident_id', 'state', 'cause', 'started_at', 'reported_at', 'recovered_at',
        'stage', 'expected_value_source', 'expected_value_lkr', 'probability_percent', 'expected_close_date',
        'won_booking_id', 'won_at', 'closed_at', 'lost_reason_code', 'confidentiality', 'state_version',
        'due_at', 'remind_at', 'reminder_dispatched_at', 'escalate_at', 'escalated_at',
        'adjustment_type', 'direction', 'adjustment_effective_at',
        'approval_type', 'priority', 'override_reasons', 'justification', 'comments',
        'rejected_at', 'auto_approved', 'approval_level', 'approver_id',
        'requested_by', 'dispatch_status', 'dispatched_at', 'dispatched_by',
        'trigger_delivered_at', 'trigger_delivery_channel',
        'expected_return_at', 'actual_return_at', 'returned_by', 'dispatch_notes',
        'return_notes', 'fuel_level_out', 'fuel_level_in', 'mileage_out', 'mileage_in',
        'vehicle_condition_out', 'vehicle_condition_in', 'damages_reported',
        'inspection_started_at', 'inspector_id', 'passed_inspection', 'repair_required', 'requires_maintenance',
        'fuel_level', 'mileage', 'next_maintenance_due', 'estimated_repair_cost',
        'additional_charges', 'late_return_fee', 'documents_generated', 'agreements_signed',
        'quantity', 'addon_id', 'price', 'variable_name',
        'variable_type', 'original_value', 'custom_value', 'customization_reason',
        'context', 'qty', 'rate', 'is_insurance', 'is_milage', 'label',
        'decision', 'actor_type', 'effective_at', 'current_step', 'total_steps',
        'booking_payment_schedule_id', 'booking_payment_receipt_id', 'receipt_id', 'allocated_at',
        'verified_at', 'submitted_by_staff_id', 'submitted_by_user_id', 'assigned_sales_profile_id', 'collection_sales_profile_id',
    ];

    public function withReasonContext(?string $reason, callable $callback): mixed
    {
        $request = request();
        $hadReason = $request->attributes->has('booking_history_reason');
        $previousReason = $request->attributes->get('booking_history_reason');
        $request->attributes->set('booking_history_reason', $reason);

        try {
            return $callback();
        } finally {
            if ($hadReason) {
                $request->attributes->set('booking_history_reason', $previousReason);
            } else {
                $request->attributes->remove('booking_history_reason');
            }
        }
    }

    public function recordFinancialAuditEvent(FinancialAuditEvent $event, string $provenance = 'live'): void
    {
        if (!$event->booking_id) return;
        $metadata = is_array($event->metadata) ? $event->metadata : [];
        $actor = $event->performed_by ? User::query()->find($event->performed_by) : null;
        $reason = $metadata['reason'] ?? request()->input('change_reason', request()->input('reason'));
        $occurredAt = $event->occurred_at?->utc() ?? now()->utc();
        $correlationId = 'financial-audit:' . $event->getKey();
        DB::table('booking_history_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'booking_id' => (string) $event->booking_id,
            'booking_item_id' => null,
            'entity_type' => (string) $event->subject_type,
            'entity_id' => (string) $event->subject_id,
            'event_type' => (string) $event->event_type,
            'actor_type' => $metadata['actor_type_snapshot'] ?? ($actor ? 'user' : 'system'),
            'actor_user_id' => $event->performed_by,
            'actor_display_snapshot' => $metadata['actor_display_snapshot']
                ?? ($actor ? (trim((string) $actor->first_name . ' ' . (string) $actor->last_name) ?: 'User ' . $actor->id) : 'System'),
            'source' => 'financial_audit_events',
            'reason' => is_string($reason) && trim($reason) !== '' ? Str::limit(trim($reason), 1000) : null,
            'changes' => json_encode(array_filter([
                'status' => $event->from_status !== $event->to_status
                    ? ['from' => $event->from_status, 'to' => $event->to_status]
                    : null,
                'amount' => $event->amount !== null ? ['from' => null, 'to' => (float) $event->amount] : null,
            ]), JSON_THROW_ON_ERROR),
            'context' => json_encode([
                'financial_audit_event_id' => (string) $event->id,
                'subject_type' => (string) $event->subject_type,
                'subject_id' => (string) $event->subject_id,
                'method' => $metadata['method'] ?? null,
                'stage' => $metadata['stage'] ?? null,
                'purpose' => $metadata['purpose'] ?? null,
                'received_via' => $metadata['received_via'] ?? null,
            ], JSON_THROW_ON_ERROR),
            'correlation_id' => $correlationId,
            'idempotency_key' => 'financial-audit:' . $event->id,
            'occurred_at' => $occurredAt,
            'provenance' => $provenance,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    public function recordBookingStatus(BookingStatus $status, string $provenance = 'live'): void
    {
        if (!$status->booking_id) return;

        $actor = $status->created_user_id ? User::query()->find($status->created_user_id) : null;
        $request = request();
        $reason = $request->input('change_reason') ?? $request->input('reason');
        $occurredAt = $status->created_at?->utc() ?? now()->utc();
        DB::table('booking_history_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'booking_id' => (string) $status->booking_id,
            'booking_item_id' => null,
            'entity_type' => 'BookingStatus',
            'entity_id' => (string) $status->getKey(),
            'event_type' => 'booking_status_changed',
            'actor_type' => $actor ? 'user' : 'system',
            'actor_user_id' => $status->created_user_id,
            'actor_display_snapshot' => $actor
                ? (trim((string) $actor->first_name . ' ' . (string) $actor->last_name) ?: 'User ' . $actor->id)
                : 'System',
            'source' => 'booking_statuses',
            'reason' => is_string($reason) && trim($reason) !== '' ? Str::limit(trim($reason), 1000) : null,
            'changes' => json_encode(['status' => [
                'from' => $this->safeValue($status->old_status),
                'to' => $this->safeValue($status->new_status),
            ]], JSON_THROW_ON_ERROR),
            'context' => json_encode([], JSON_THROW_ON_ERROR),
            'correlation_id' => 'booking-status:' . $status->getKey(),
            'idempotency_key' => 'booking-status:' . $status->getKey(),
            'occurred_at' => $occurredAt,
            'provenance' => $provenance,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    public function recordBookingApprovalDecision(string $bookingId, string $approvalId, string $decision, ?string $comments, string $userId): void
    {
        $this->recordDirectChange(
            $bookingId,
            'BookingApprovalDecision',
            $approvalId,
            'booking_approval_' . $decision,
            ['decision' => ['from' => null, 'to' => $decision]],
            $comments,
            $userId,
            'booking_approval_decisions',
            'booking-approval-decision:' . $approvalId . ':' . $userId . ':' . $decision,
        );
    }

    private function recordDirectChange(
        string $bookingId,
        string $entityType,
        string $entityId,
        string $eventType,
        array $changes,
        ?string $reason,
        ?string $actorUserId,
        string $source,
        ?string $idempotencyKey = null,
    ): void {
        $actor = $actorUserId ? User::query()->find($actorUserId) : null;
        $now = now()->utc();
        DB::table('booking_history_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'booking_id' => $bookingId,
            'booking_item_id' => null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_type' => $eventType,
            'actor_type' => $actor ? 'user' : 'system',
            'actor_user_id' => $actorUserId,
            'actor_display_snapshot' => $actor
                ? (trim((string) $actor->first_name . ' ' . (string) $actor->last_name) ?: 'User ' . $actor->id)
                : 'System',
            'source' => $source,
            'reason' => $reason !== null && trim($reason) !== '' ? Str::limit(trim($reason), 1000) : null,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'context' => json_encode([], JSON_THROW_ON_ERROR),
            'correlation_id' => $idempotencyKey,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => $now,
            'provenance' => 'live',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function recordModelChange(Model $model, string $operation, array $before = [], ?string $reasonOverride = null): void
    {
        $linkedAssignment = null;
        $bookingId = $model instanceof \App\Models\Booking\Booking
            ? (string) $model->getKey()
            : (string) ($model->getAttribute('booking_id')
                ?? ($before['booking_id'] ?? null)
                ?? $model->getAttribute('linked_booking_id')
                ?? ($before['linked_booking_id'] ?? null)
                ?? $model->getAttribute('won_booking_id')
                ?? ($before['won_booking_id'] ?? null)
                ?? $model->receipt?->booking_id
                ?? $model->schedule?->booking_id
                ?? $model->rule?->booking_id
                ?? '');
        if ($bookingId === '' && $model instanceof \App\Models\Driver\DriverSession) {
            $assignmentId = $model->assignment_id ?? ($before['assignment_id'] ?? null);
            $linkedAssignment = $assignmentId ? \App\Models\DriverAssignment::withTrashed()->find($assignmentId) : null;
            $bookingId = (string) ($linkedAssignment?->booking_id ?? '');
        }
        if ($bookingId === '') return;

        $changes = [];
        foreach (self::TRACKED_FIELDS as $field) {
            if (array_key_exists($field, $before) || array_key_exists($field, $model->getAttributes())) {
                $old = $before[$field] ?? null;
                $new = $model->getAttribute($field);
                if (in_array($operation, ['created', 'deleted', 'restored'], true) || $old !== $new) {
                    $redact = in_array($field, [
                        'special_requirements', 'emergency_contact_name', 'emergency_contact_phone',
                        'details', 'context', 'workflow_data', 'conditions', 'notes', 'confirmation_notes',
                        'pickup_location', 'pickup_latitude', 'pickup_longitude',
                        'dropoff_location', 'dropoff_latitude', 'dropoff_longitude', 'address', 'location',
                        'latitude', 'longitude', 'accept_latitude', 'accept_longitude', 'final_latitude',
                        'final_longitude', 'pickup_arrival_latitude', 'pickup_arrival_longitude',
                        'trip_start_latitude', 'trip_start_longitude', 'driver_name_snapshot',
                        'assigned_by_name_snapshot', 'confirmed_by_name_snapshot',
                    ], true);
                    $changes[$field] = [
                        'from' => $operation === 'created' || $operation === 'restored'
                            ? null
                            : ($redact && $old !== null ? '[redacted]' : $this->safeValue($old)),
                        'to' => $operation === 'deleted'
                            ? null
                            : ($redact && $new !== null ? '[redacted]' : $this->safeValue($new)),
                    ];
                }
            }
        }
        $changedFields = match ($operation) {
            'created' => array_keys($model->getAttributes()),
            'updated' => array_keys($model->getChanges()),
            default => [],
        };
        $untrackedFields = array_values(array_diff(
            $changedFields,
            self::TRACKED_FIELDS,
            ['id', 'booking_id', 'booking_item_id', 'created_at', 'updated_at', 'deleted_at', 'created_user_id', 'updated_user_id'],
        ));
        if ($untrackedFields !== []) {
            sort($untrackedFields);
            $changes['other_fields'] = ['from' => null, 'to' => implode(', ', $untrackedFields)];
        }
        if ($operation === 'updated' && $changes === []) return;

        $request = request();
        $actor = Auth::user();
        if (!$actor) {
            $actorId = $model->getAttribute('updated_user_id')
                ?? ($operation === 'created' ? $model->getAttribute('created_user_id') : null)
                ?? ($model instanceof \App\Models\Booking\BookingPaymentScheduleRevision ? $model->getAttribute('approved_by') : null)
                ?? $model->getAttribute('resolved_by')
                ?? ($before['updated_user_id'] ?? null);
            $actor = $actorId ? User::query()->find($actorId) : null;
        }
        $requestReason = $reasonOverride;
        if ($requestReason === null && $request->attributes->has('booking_history_reason')) {
            $requestReason = $request->attributes->get('booking_history_reason');
        } elseif ($requestReason === null) {
            $requestReason = $request->input('change_reason')
                ?? data_get($model->getAttribute('workflow_data'), 'cancellation_reason')
                ?? data_get($model->getAttribute('workflow_data'), 'approval_reason')
                ?? data_get($model->getAttribute('meta'), 'reason')
                ?? $model->getAttribute('approval_note')
                ?? $model->getAttribute('approval_justification')
                ?? $model->getAttribute('resolution')
                ?? $model->getAttribute('price_override_reason')
                ?? $model->getAttribute('justification')
                ?? $model->getAttribute('customization_reason')
                ?? (in_array(class_basename($model), ['Booking', 'BookingItem'], true) ? null : $model->getAttribute('reason'))
                ?? $request->input('reason')
                ?? $model->getAttribute('assignment_notes');
        }
        $reason = is_string($requestReason) ? trim($requestReason) : null;
        $id = (string) Str::uuid();
        $now = now()->utc();
        DB::table('booking_history_events')->insert([
            'id' => $id,
            'booking_id' => $bookingId,
            'booking_item_id' => $model instanceof \App\Models\Booking\BookingItem
                ? (string) $model->getKey()
                : ($model->getAttribute('booking_item_id')
                    ? (string) $model->getAttribute('booking_item_id')
                    : ($linkedAssignment?->booking_item_id ? (string) $linkedAssignment->booking_item_id : null)),
            'entity_type' => class_basename($model),
            'entity_id' => (string) $model->getKey(),
            'event_type' => $this->eventType($model, $operation, $changes),
            'actor_type' => $actor ? 'user' : ($model instanceof \App\Models\IntegrationWebhookEvent ? 'integration' : 'system'),
            'actor_user_id' => $actor?->getAuthIdentifier(),
            'actor_display_snapshot' => $actor
                ? trim((string) ($actor->full_name ?? $actor->name ?? $actor->email))
                : ($model instanceof \App\Models\IntegrationWebhookEvent ? 'Integration: ' . $model->provider : 'System'),
            'source' => Str::limit($request->route()?->getName() ?: ($request->route()?->uri() ?: (app()->runningInConsole() ? 'console' : 'application')), 100),
            'reason' => $reason !== '' ? Str::limit($reason, 1000) : null,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'context' => json_encode(array_filter([
                'booking_number' => $model instanceof \App\Models\Booking\Booking
                    ? $model->booking_number
                    : null,
                'item_code' => $model instanceof \App\Models\Booking\BookingItem
                    ? $model->item_code
                    : null,
            ]), JSON_THROW_ON_ERROR),
            'correlation_id' => $request->headers->get('X-Correlation-ID')
                ?: $request->headers->get('X-Request-ID'),
            'occurred_at' => $now,
            'provenance' => 'live',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function eventType(Model $model, string $operation, array $changes): string
    {
        $class = class_basename($model);
        if ($model instanceof \App\Models\Booking\Booking && isset($changes['status'])) return 'booking_status_changed';
        if ($model instanceof \App\Models\Booking\Booking && $operation === 'created') return 'booking_created';
        if ($model instanceof BookingStatus) return 'booking_status_record_' . $operation;
        if (isset($changes['trip_phase'])) return 'booking_trip_phase_changed';
        if ($model instanceof \App\Models\Driver\DriverTrackingIncident) return 'booking_tracking_incident_' . (isset($changes['state']) ? 'state_changed' : $operation);
        if ($model instanceof \App\Models\Driver\DriverLog) return 'booking_driver_log_' . (isset($changes['status']) ? 'status_changed' : $operation);
        if ($model instanceof \App\Models\Driver\DriverIouAdvance) return 'booking_driver_iou_' . $operation;
        if ($model instanceof \App\Models\Vehicle\VehicleMaintenanceSchedule) return 'booking_maintenance_schedule_' . (isset($changes['status']) ? 'status_changed' : $operation);
        if ($model instanceof \App\Models\Corporate\CorporateTransportGenerationLog) return 'booking_transport_generation_' . (isset($changes['status']) ? 'status_changed' : $operation);
        if ($model instanceof \App\Models\Sales\SalesOpportunity) return 'booking_sales_opportunity_' . $operation;
        if ($model instanceof \App\Models\Sales\SalesAttributionException) {
            return $model->status === 'resolved' ? 'booking_sales_attribution_exception_resolved' : 'booking_sales_attribution_exception_' . $operation;
        }
        if ($model instanceof \App\Models\Sales\SalesTask) return 'booking_sales_task_' . (isset($changes['status']) ? 'status_changed' : $operation);
        if ($model instanceof \App\Models\Vehicle\VehiclePricing\BookingPriceAdjustmentHistory) return 'booking_price_adjustment_recorded';
        if ($model instanceof \App\Models\Booking\BookingPaymentReceiptFinalityEvent) return 'payment_receipt_finality_changed';
        if ($model instanceof \App\Models\Booking\BookingCollectionSubmission) {
            return isset($changes['status']) ? 'collection_submission_decided' : 'collection_submission_recorded';
        }
        if ($model instanceof \App\Models\Booking\BookingCollectionReminderDelivery) return 'booking_collection_reminder_dispatched';
        if ($model instanceof \App\Models\Booking\BookingPaymentScheduleRuleEvent) return 'collection_schedule_rule_changed';
        if ($model instanceof \App\Models\Booking\BookingPaymentScheduleRevision) return 'collection_schedule_revised';
        if ($model instanceof \App\Models\Booking\BookingPaymentAdjustment) return 'booking_payment_adjustment_recorded';
        if ($model instanceof \App\Models\Booking\BookingPaymentScheduleAllocation) return 'payment_allocated_to_schedule';
        if ($model instanceof \App\Models\Booking\BookingDiscount) return 'booking_discount_' . $operation;
        if ($model instanceof \App\Models\Booking\BookingDepositRefund) return 'booking_deposit_refund_recorded';
        if ($model instanceof \App\Models\Booking\BookingExtensionRequest) return 'booking_extension_' . (isset($changes['status']) ? 'status_changed' : $operation);
        if ($model instanceof \App\Models\Booking\BookingQC) return 'booking_quality_check_' . (isset($changes['qc_status']) ? 'status_changed' : $operation);
        if ($model instanceof \App\Models\Booking\BookingCustomerMobileActivity) return 'booking_customer_mobile_activity_recorded';
        if ($model instanceof \App\Models\Booking\BookingCollectionCommission) return 'booking_collection_commission_' . $operation;
        if ($model instanceof \App\Models\Booking\BookingCommercialValueAdjustment) return 'booking_commercial_value_adjustment_recorded';
        if ($model instanceof \App\Models\Booking\BookingPricing || $model instanceof \App\Models\Booking\BookingCommonRatePricing) return 'booking_price_component_' . $operation;
        if ($model instanceof \App\Models\Booking\BookingTerm) return 'booking_terms_' . $operation;
        if ($model instanceof \App\Models\BookingPriceAdjustmentHistory) return 'booking_price_adjustment_recorded';
        if ($model instanceof \App\Models\Finance\FinancialAdjustment) return 'booking_financial_adjustment_recorded';
        if ($model instanceof \App\Models\Finance\FinancialPaymentAllocation) return 'booking_payment_allocation_recorded';
        if ($model instanceof \App\Models\Sales\SalesBookingAttribution) return 'booking_sales_attribution_' . $operation;
        if ($model instanceof \App\Models\Sales\SalesCommissionDecision) return 'booking_commission_decision_' . $operation;
        if ($model instanceof \App\Models\Invoice) return 'booking_invoice_' . $operation;
        if ($model instanceof \App\Models\PaymentEvent) return 'booking_payment_event_' . $operation;
        if ($model instanceof \App\Models\PromoCodeUsage) return 'booking_promo_code_' . $operation;
        if ($model instanceof \App\Models\DriverAssignmentStop) return 'booking_driver_stop_' . (isset($changes['status']) ? 'status_changed' : $operation);
        if ($model instanceof \App\Models\IntegrationWebhookEvent) return 'booking_integration_event_' . $operation;
        if ($model instanceof \App\Models\Driver\DriverSession && isset($changes['assignment_id'])) return 'booking_driver_session_assignment_changed';
        if ($model instanceof \App\Models\Inquiry) return 'booking_linked_inquiry_' . (isset($changes['status']) ? 'status_changed' : $operation);
        if ($model instanceof \App\Models\PhoneCall) return 'booking_linked_phone_call_' . $operation;
        if ($model instanceof \App\Models\LoyaltyPointTransaction) return 'booking_loyalty_points_' . $operation;
        if ($model instanceof \App\Models\Finance\FinancialSettlementItem) return 'booking_financial_settlement_item_' . $operation;
        if ($model instanceof \App\Models\Finance\DriverCashSettlementItem) return 'booking_driver_cash_settlement_item_' . $operation;
        if ($model instanceof \App\Models\Driver\DriverHireSettlement) return 'booking_driver_hire_settlement_' . (isset($changes['status']) ? 'status_changed' : $operation);
        if ($model instanceof \App\Models\Agent\AgentCommission) return 'booking_agent_commission_' . $operation;
        if ($model instanceof \App\Models\Corporate\CorporateTransportParticipation) return 'booking_passenger_participation_' . $operation;
        if ($model instanceof \App\Models\Sales\SalesActivity) return 'booking_sales_activity_' . $operation;
        if (isset($changes['status']) || isset($changes['dispatch_status'])) return strtolower($class) . '_status_changed';
        if (isset($changes['vehicle_id']) || isset($changes['driver_id'])) return 'booking_assignment_changed';
        if (isset($changes['total_price']) || isset($changes['unit_price']) || isset($changes['currency'])) return 'booking_price_changed';
        if (isset($changes['amount']) || isset($changes['refunded_amount']) || isset($changes['finality_status'])) return 'booking_payment_changed';

        return strtolower($class) . '_' . $operation;
    }

    private function safeValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) return $value->value;
        if ($value instanceof \DateTimeInterface) return $value->format(DATE_ATOM);
        if (is_scalar($value) || $value === null) return $value;
        return '[redacted]';
    }
}
