<?php

namespace App\Services;

use App\Models\Finance\FinancialAuditEvent;
use App\Models\Booking\BookingActivity;
use App\Models\Booking\BookingStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingHistoryService
{
    private const BOOKING_ACTIVITY_EVENT_KEYS = [
        'booking_updated', 'booking_vehicle_changed', 'resource_swap_completed',
        'booking_responsible_user_changed', 'rental_return_no_show',
        'replacement_requested', 'replacement_request_closed',
        'rental_extension_requested', 'rental_extension_approved', 'rental_extension_rejected',
        'trip_price_changed', 'additional_rental_charge_added', 'post_trip_maintenance',
        'booking_inquiry_created',
    ];

    private const TRACKED_FIELDS = [
        'status', 'old_status', 'new_status', 'payment_status', 'payment_collection_status',
        'booking_channel_id', 'booking_source', 'booking_origin', 'booking_date', 'booked_at', 'created_from',
        'invoice_number', 'log_code', 'vip_id', 'third_party_ref', 'booking_number', 'created_by_user_id',
        'trip_status', 'invoice_status', 'trip_ended_at',
        'is_recurring', 'recurrence_pattern', 'recurrence_days', 'recurrence_end_date',
        'recurring_occurrence_date', 'recurring_sequence', 'recurring_series_id',
        'actual_distance', 'actual_duration', 'estimated_distance', 'estimated_duration',
        'amount_to_pay', 'payment_arrangement_status', 'payment_collected_amount', 'payment_collected_at',
        'payment_collected_by_driver_id', 'payment_collection_method', 'payment_reference', 'payment_type',
        'payment_responsibility', 'payment_schedule_frequency', 'payment_schedule_start_date',
        'payment_schedule_installment_amount', 'payment_schedule_reminder_days', 'payment_notes', 'settlement_due_date',
        'corporate_settlement_status', 'customer_settlement_status', 'driver_collection_status', 'refund_status',
        'workflow_step', 'workflow_data', 'confirmation_number', 'approval_priority', 'approval_requested_at', 'approval_requested_by',
        'customer_id', 'agent_id', 'responsible_user_id', 'passenger_count', 'luggage_count', 'driver_hire_settlement_id', 'issued_date', 'issued_by',
        'special_requirements', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
        'insurance_type', 'safety_features_required', 'notification_sms', 'notification_email',
        'notification_whatsapp', 'notification_push', 'skip_all_emails',
        'review_notes', 'base_amount', 'total_estimated', 'total_actual', 'driver_cost',
        'distance_cost', 'addons_cost',
        'is_corporate_booking', 'corporate_account_id',
        'corporate_department_id', 'corporate_division_id', 'employee_id',
        'corporate_approval_status', 'corporate_approval_by', 'corporate_approval_at', 'corporate_approval_note',
        'corporate_approval_step', 'corporate_approval_history',
        'cost_center', 'project_code', 'service_type_id', 'vehicle_group_id',
        'vehicle_class_id', 'pricing_basis', 'vehicle_id', 'driver_id', 'from_date', 'from_time',
        'to_date', 'to_time', 'pickup_landmark', 'dropoff_landmark',
        'item_type', 'item_code', 'duration_days', 'duration_hours', 'duration_minutes', 'is_self_driven',
        'pickup_location', 'pickup_latitude', 'pickup_longitude', 'dropoff_location', 'dropoff_latitude', 'dropoff_longitude',
        'final_priced_at', 'price_overridden_at', 'price_overridden_by', 'price_override_amount', 'price_override_reason',
        'unit_price', 'total_price', 'currency', 'exchange_rate', 'discount_amount', 'tax_amount', 'confirmed',
        'slab_definition_id', 'vehicle_group_pricing_id', 'common_rate_definition_id',
        'vehicle_group_common_rate_pricing_id', 'calculation_base', 'is_mandatory',
        'sequence', 'label', 'period_start', 'period_end', 'schedule_kind', 'reconciliation_role',
        'is_collection_target_eligible', 'collection_sales_profile_id', 'superseded_at',
        'superseded_by_revision_id', 'booking_payment_schedule_rule_id', 'rule_occurrence_number',
        'reminder_sent_at', 'overdue_notified_at', 'reconciliation_rule', 'work_type',
        'reminder_offset_days', 'last_reminded_at', 'allocated_by', 'event_version', 'finalized_at',
        'initial_finality_status', 'payer_type', 'payer_id', 'corporate_remittance_id',
        'contract_basis', 'frequency', 'frequency_months', 'anchor_date', 'reminder_offset_days',
        'horizon_months', 'last_generated_occurrence', 'last_generated_through',
        'description', 'loyalty_points_used', 'points_to_amount_rate', 'application_method',
        'discount_code', 'applied_by', 'valid_from', 'valid_until', 'conditions_met',
        'calculation_details', 'internal_notes', 'impact_dimension', 'fx_quote_base', 'fx_calculation_mode',
        'before_snapshot', 'after_snapshot',
        'original_fx_source', 'lkr_delta', 'cumulative_lkr_delta', 'commission_decision_id',
        'corrects_adjustment_id', 'lineage_root_adjustment_id', 'correction_sequence',
        'prior_corrected_lkr_amount', 'prior_fx_rate_to_lkr', 'prior_fx_rate_at', 'prior_fx_source',
        'contractual_source_amount', 'retained_source_amount', 'replacement_source_amount',
        'reconciliation_amount', 'contractual_lkr_amount', 'retained_lkr_amount', 'replacement_lkr_amount',
        'confirmed_at', 'completed_at', 'returned_at', 'cancelled_at', 'approval_status', 'approval_justification', 'status', 'approved_by',
        'approval_by', 'approval_at', 'approval_note',
        'requires_approval', 'approved_at', 'actual_start', 'actual_end', 'hourly_rate',
        'manager_id', 'requested_at', 'processed_at', 'processed_by', 'conditions', 'notes',
        'trip_phase', 'trip_completed_at',
        'assigned_from', 'assigned_to', 'assignment_type', 'assignment_notes', 'service_type', 'overlap_type',
        'manually_confirmed', 'overtime_applicable', 'mileage_start', 'mileage_end', 'overlap_details',
        'override_reasons', 'maintenance_window', 'approval_notes', 'booking_device_snapshot', 'customer_name',
        'issues_reported', 'repair_notes', 'qc_notes', 'photos',
        'confirmation_method', 'confirmation_notes', 'confirmed_by', 'confirmed_by_name_snapshot',
        'assigned_by_name_snapshot', 'driver_name_snapshot', 'decline_reason', 'parent_assignment_id',
        'pickup_arrived_at', 'trip_started_at', 'accept_latitude', 'accept_longitude', 'final_latitude', 'final_longitude',
        'pickup_arrival_latitude', 'pickup_arrival_longitude', 'trip_start_latitude', 'trip_start_longitude',
        'current_latitude', 'current_longitude', 'location_updated_at',
        'toll_charges_included', 'fuel_charges_included', 'parking_charges_included',
        'customer_rating', 'customer_feedback', 'customer_feedback_submitted_at', 'customer_feedback_response',
        'customer_feedback_responded_at', 'customer_feedback_responded_by', 'driver_rating', 'driver_feedback',
        'base_price_override', 'base_price_override_reason', 'base_price_edited_by', 'base_price_edited_at',
        'addon_overrides', 'original_totals', 'edited_totals', 'gamify_points_earned', 'gamify_discount_applied',
        'gamify_details', 'has_overrides', 'concurrent_assignments', 'pricing_snapshot', 'duration_metrics', 'distance_metrics',
        'lifecycle_data', 'workflow_config_snapshot',
        'availability_status', 'current_mileage', 'unavailable_reason', 'estimated_available_date', 'last_qc_date',
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
        'event_type', 'event_key', 'result_status', 'channel', 'source', 'message_direction', 'outbound_status',
        'stop_type', 'route_order', 'completed_at', 'completed_action', 'skip_reason',
        'type_sequence', 'arrived_at', 'address', 'location', 'latitude', 'longitude',
        'arrived_latitude', 'arrived_longitude', 'completed_latitude', 'completed_longitude',
        'assignment_id', 'booking_item_id', 'start_time', 'end_time', 'duration_seconds', 'total_distance_km', 'linked_booking_id',
        'call_status', 'follow_up_due_at', 'follow_up_completed_at',
        'exception_type', 'details', 'resolved_by', 'resolved_at', 'detected_at',
        'booking_collection_work_item_id', 'recipient_user_id', 'reminder_type', 'channel',
        'actor_user_id', 'assigned_sales_profile_id', 'promised_payment_date', 'next_follow_up_at',
        'request_payload_checksum', 'occurred_at',
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
        'performed_date', 'cost',
        'additional_charges', 'late_return_fee', 'documents_generated', 'agreements_signed',
        'quantity', 'addon_id', 'price', 'variable_name', 'addons', 'customizations', 'discounts',
        'variable_type', 'original_value', 'custom_value', 'customization_reason',
        'context', 'qty', 'rate', 'is_insurance', 'is_milage', 'label',
        'decision', 'actor_type', 'effective_at', 'current_step', 'total_steps',
        'booking_payment_schedule_id', 'booking_payment_receipt_id', 'booking_payment_schedule_rule_id',
        'receipt_id', 'allocated_at', 'component_type', 'is_allocatable', 'is_collection_target_eligible',
        'is_commission_eligible', 'allocated_source_amount', 'adjusted_source_amount', 'version', 'from_status',
        'to_status', 'effective_at',
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

    public function withBookingContext(string $bookingId, ?string $bookingItemId, callable $callback): mixed
    {
        $request = request();
        $attributes = [
            'booking_history_booking_id' => $bookingId,
            'booking_history_booking_item_id' => $bookingItemId,
        ];
        $previous = [];
        foreach ($attributes as $key => $value) {
            $previous[$key] = [$request->attributes->has($key), $request->attributes->get($key)];
            $request->attributes->set($key, $value);
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $key => [$existed, $value]) {
                $existed ? $request->attributes->set($key, $value) : $request->attributes->remove($key);
            }
        }
    }

    public function recordFinancialAuditEvent(FinancialAuditEvent $event, string $provenance = 'live'): void
    {
        if (!$event->booking_id) return;
        $metadata = is_array($event->metadata) ? $event->metadata : [];
        $actor = $event->performed_by ? User::query()->find($event->performed_by) : null;
        $request = request();
        $reason = $metadata['reason']
            ?? $request->attributes->get('booking_history_reason')
            ?? $request->input('change_reason', $request->input('reason'));
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
            'context' => json_encode(array_filter([
                'financial_audit_event_id' => (string) $event->id,
                'subject_type' => (string) $event->subject_type,
                'subject_id' => (string) $event->subject_id,
                'method' => $metadata['method'] ?? null,
                'stage' => $metadata['stage'] ?? null,
                'purpose' => $metadata['purpose'] ?? null,
                'received_via' => $metadata['received_via'] ?? null,
                'source_trigger' => $request->attributes->get('booking_history_source_trigger'),
            ], fn ($value) => $value !== null), JSON_THROW_ON_ERROR),
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
        $reason = $request->attributes->get('booking_history_reason')
            ?? $request->input('change_reason')
            ?? $request->input('reason');
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
            'context' => json_encode(array_filter([
                'source_trigger' => $request->attributes->get('booking_history_source_trigger'),
            ], fn ($value) => $value !== null), JSON_THROW_ON_ERROR),
            'correlation_id' => 'booking-status:' . $status->getKey(),
            'idempotency_key' => 'booking-status:' . $status->getKey(),
            'occurred_at' => $occurredAt,
            'provenance' => $provenance,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    public function recordBookingActivity(BookingActivity $activity, array $before = []): void
    {
        if (!$this->shouldRecordBookingActivity($activity)) return;

        $meta = is_array($activity->meta) ? $activity->meta : [];
        $actorId = data_get($meta, 'actor_user_id') ?? data_get($meta, 'initiated_by')
            ?? data_get($meta, 'changed_by') ?? data_get($meta, 'requested_by')
            ?? data_get($meta, 'reviewed_by') ?? data_get($meta, 'closed_by')
            ?? data_get($meta, 'completed_by') ?? data_get($meta, 'queued_by')
            ?? $activity->created_user_id;
        $actor = $actorId ? User::query()->find($actorId) : null;
        $changes = match ($activity->event_key) {
            'resource_swap_completed' => array_filter([
                'vehicle_id' => [
                    'from' => data_get($meta, 'old_vehicle_snapshot.label') ?: data_get($meta, 'old_vehicle_id'),
                    'to' => data_get($meta, 'new_vehicle_snapshot.label') ?: data_get($meta, 'new_vehicle_id'),
                ],
                'driver_id' => [
                    'from' => data_get($meta, 'old_driver_snapshot.label') ?: data_get($meta, 'old_driver_id'),
                    'to' => data_get($meta, 'new_driver_snapshot.label') ?: data_get($meta, 'new_driver_id'),
                ],
            ], fn (array $change): bool => $change['from'] !== null || $change['to'] !== null),
            'booking_vehicle_changed' => ['vehicle_id' => [
                'from' => data_get($meta, 'previous_vehicle_id'), 'to' => data_get($meta, 'new_vehicle_id'),
            ]],
            'booking_responsible_user_changed' => ['responsible_user_id' => [
                'from' => data_get($meta, 'from_user_id'), 'to' => data_get($meta, 'to_user_id'),
            ]],
            'trip_price_changed' => array_filter([
                'total_price' => ['from' => data_get($meta, 'previous_price'), 'to' => data_get($meta, 'final_price')],
                'currency' => ['from' => data_get($meta, 'previous_currency'), 'to' => data_get($meta, 'currency')],
            ], fn (array $change): bool => $change['from'] !== null || $change['to'] !== null),
            'additional_rental_charge_added' => array_filter([
                'charge_amount' => ['from' => null, 'to' => data_get($meta, 'amount')],
                'currency' => ['from' => null, 'to' => data_get($meta, 'currency')],
            ], fn (array $change): bool => $change['to'] !== null),
            'rental_extension_requested' => array_filter([
                'to_date' => ['from' => data_get($meta, 'current_end_at'), 'to' => data_get($meta, 'requested_end_at')],
                'charge_amount' => ['from' => null, 'to' => data_get($meta, 'additional_charge')],
            ], fn (array $change): bool => $change['from'] !== null || $change['to'] !== null),
            'rental_extension_approved', 'rental_extension_rejected' => [
                'decision' => ['from' => null, 'to' => str_replace('rental_extension_', '', (string) $activity->event_key)],
            ],
            'replacement_requested' => ['replacement_request_status' => ['from' => null, 'to' => 'pending']],
            'replacement_request_closed' => ['replacement_request_status' => ['from' => 'pending', 'to' => 'closed']],
            'post_trip_maintenance' => ['result_status' => [
                'from' => $before['result_status'] ?? null,
                'to' => $activity->result_status,
            ]],
            default => [],
        };
        $reason = data_get($meta, 'reason') ?? data_get($meta, 'change_reason')
            ?? data_get($meta, 'resolution_reason') ?? data_get($meta, 'review_notes')
            ?? request()->attributes->get('booking_history_reason');
        [$entityType, $entityId] = match (true) {
            $activity->event_key === 'post_trip_maintenance' && data_get($meta, 'vehicle_id')
                => ['Vehicle', (string) data_get($meta, 'vehicle_id')],
            $activity->event_key === 'booking_inquiry_created' && data_get($meta, 'phone_call_id')
                => ['PhoneCall', (string) data_get($meta, 'phone_call_id')],
            $activity->event_key === 'booking_inquiry_created' && data_get($meta, 'respond_io_event_id')
                => ['IntegrationWebhookEvent', (string) data_get($meta, 'respond_io_event_id')],
            default => ['BookingActivity', (string) $activity->getKey()],
        };
        $occurredAt = $activity->event_at?->utc() ?? $activity->created_at?->utc() ?? now()->utc();

        DB::table('booking_history_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'booking_id' => (string) $activity->booking_id,
            'booking_item_id' => $activity->booking_item_id ? (string) $activity->booking_item_id : null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_type' => (string) $activity->event_key,
            'actor_type' => $actor ? 'user' : 'system',
            'actor_user_id' => $actor?->getAuthIdentifier(),
            'actor_display_snapshot' => data_get($meta, 'actor_display_snapshot')
                ?: ($actor ? (trim((string) $actor->first_name . ' ' . $actor->last_name) ?: 'User ' . $actor->id) : 'System'),
            'source' => 'booking_activities',
            'reason' => is_string($reason) && trim($reason) !== '' ? Str::limit(trim($reason), 1000) : null,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'context' => json_encode(array_filter([
                'source_activity_id' => (string) $activity->getKey(),
                'activity_source' => $activity->source,
                'activity_status' => $activity->result_status,
                'swap_type' => data_get($meta, 'swap_type'),
                'vehicle_id' => data_get($meta, 'vehicle_id'),
                'old_vehicle_snapshot' => data_get($meta, 'old_vehicle_snapshot'),
                'new_vehicle_snapshot' => data_get($meta, 'new_vehicle_snapshot'),
                'old_driver_snapshot' => data_get($meta, 'old_driver_snapshot'),
                'new_driver_snapshot' => data_get($meta, 'new_driver_snapshot'),
                'origin_entity_type' => $entityType !== 'BookingActivity' ? $entityType : null,
                'origin_entity_id' => $entityType !== 'BookingActivity' ? $entityId : null,
                'category' => data_get($meta, 'category'),
                'changed_fields' => data_get($meta, 'changed_fields'),
                'source_trigger' => request()->attributes->get('booking_history_source_trigger'),
            ], fn ($value) => $value !== null), JSON_THROW_ON_ERROR),
            'correlation_id' => $activity->idempotency_key ?: 'booking-activity:' . $activity->getKey(),
            'idempotency_key' => $activity->event_key === 'post_trip_maintenance'
                ? 'booking-activity:' . $activity->getKey() . ':' . $activity->result_status
                : 'booking-activity:' . $activity->getKey(),
            'occurred_at' => $occurredAt,
            'provenance' => 'live',
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    public function recordSalesAttributionEvent(object $event, string $bookingId, string $provenance = 'live'): void
    {
        $actor = $event->actor_user_id ? User::query()->find($event->actor_user_id) : null;
        $eventType = (string) ($event->event_type ?? 'changed');
        $field = $event->field_name ?? ($eventType === 'confirmed' ? 'collection_sales_profile_id' : null);
        $changes = $field !== null ? [$field => [
            'from' => $event->from_value ?? null,
            'to' => $event->to_value ?? ($eventType === 'confirmed' ? ($event->to_sales_profile_id ?? null) : null),
        ]] : [];
        $reason = $eventType === 'confirmed' ? null : ($event->reason ?? null);
        $occurredAt = isset($event->created_at)
            ? CarbonImmutable::parse($event->created_at)->utc()
            : (isset($event->effective_at) ? CarbonImmutable::parse($event->effective_at)->utc() : now()->utc());
        $sourceEventId = (string) $event->id;

        DB::table('booking_history_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'booking_id' => $bookingId,
            'booking_item_id' => null,
            'entity_type' => 'SalesBookingAttributionEvent',
            'entity_id' => $sourceEventId,
            'event_type' => 'booking_attribution_' . $eventType,
            'actor_type' => $actor ? 'user' : 'system',
            'actor_user_id' => $event->actor_user_id ?? null,
            'actor_display_snapshot' => $actor
                ? (trim((string) $actor->first_name . ' ' . $actor->last_name) ?: 'User ' . $actor->id)
                : 'System',
            'source' => 'sales_booking_attribution_events',
            'reason' => is_string($reason) && trim($reason) !== '' ? Str::limit(trim($reason), 1000) : null,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'context' => json_encode(array_filter([
                'source_attribution_event_id' => $sourceEventId,
                'attribution_id' => $event->attribution_id ?? null,
                'version' => $event->version ?? null,
                'field_name' => $field,
                'from_sales_profile_id' => $event->from_sales_profile_id ?? null,
                'to_sales_profile_id' => $event->to_sales_profile_id ?? null,
                'effective_at' => isset($event->effective_at) ? CarbonImmutable::parse($event->effective_at)->utc()->toIso8601String() : null,
                'trigger' => $eventType === 'confirmed' ? 'booking_confirmation' : null,
                'source_trigger' => request()->attributes->get('booking_history_source_trigger'),
            ], fn ($value) => $value !== null), JSON_THROW_ON_ERROR),
            'correlation_id' => $event->idempotency_key ?? null,
            'idempotency_key' => 'sales-attribution-event:' . $sourceEventId,
            'occurred_at' => $occurredAt,
            'provenance' => $provenance,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    public function shouldRecordBookingActivity(BookingActivity $activity, ?string $previousEventKey = null): bool
    {
        return (bool) $activity->booking_id && (
            in_array($activity->event_key, self::BOOKING_ACTIVITY_EVENT_KEYS, true)
            || in_array($previousEventKey, self::BOOKING_ACTIVITY_EVENT_KEYS, true)
        );
    }

    public function recordBookingApprovalDecision(
        string $bookingId,
        string $approvalId,
        string $decision,
        ?string $comments,
        string $userId,
        ?string $decisionId = null,
        ?string $idempotencyKey = null,
        string $source = 'booking_approval_decisions',
        string $entityType = 'BookingApprovalDecision',
        array $context = [],
    ): void
    {
        $entityId = $decisionId ?: $approvalId;
        $this->recordDirectChange(
            $bookingId,
            $entityType,
            $entityId,
            'booking_approval_' . $decision,
            ['decision' => ['from' => null, 'to' => $decision]],
            $comments,
            $userId,
            $source,
            $idempotencyKey ?: 'booking-approval-decision:' . $entityId,
            $context,
        );
    }

    public function recordNotificationDismissal(string $bookingId, string $notificationId, string $userId): void
    {
        $this->recordDirectChange(
            $bookingId,
            'UserNotification',
            $notificationId,
            'notification_dismissed',
            ['dismissed' => ['from' => false, 'to' => true]],
            'Recipient dismissed the actionable notification in the portal.',
            $userId,
            'notifications',
            'notification-dismissal:' . $notificationId,
            ['notification_id' => $notificationId],
        );
    }

    public function recordPaymentTransactionStatusChange(
        string $bookingId,
        string $transactionId,
        ?string $fromStatus,
        string $toStatus,
        ?string $actorUserId = null,
    ): void {
        $this->recordDirectChange(
            $bookingId,
            'PaymentTransaction',
            $transactionId,
            'payment_transaction_status_changed',
            ['status' => ['from' => $fromStatus, 'to' => $toStatus]],
            null,
            $actorUserId,
            'payment_transactions',
            'payment-transaction-status:' . $transactionId . ':' . $toStatus,
        );
    }

    public function recordPaymentGatewaySessionOutcome(
        string $bookingId,
        string $transactionId,
        string $outcome,
        ?string $actorUserId = null,
    ): void {
        $this->recordDirectChange(
            $bookingId,
            'PaymentTransaction',
            $transactionId,
            'payment_gateway_session_updated',
            ['gateway_session_status' => ['from' => null, 'to' => $outcome]],
            null,
            $actorUserId,
            'payment_transactions',
            'payment-transaction-gateway-session:' . $transactionId,
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
        array $context = [],
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
            'context' => json_encode(array_filter($context + [
                'source_trigger' => request()->attributes->get('booking_history_source_trigger'),
            ], fn ($value) => $value !== null), JSON_THROW_ON_ERROR),
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
        $linkedQc = null;
        $linkedMaintenanceSchedule = null;
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
                ?? $model->rollingRule?->booking_id
                ?? '');
        if ($bookingId === '' && ($model->getAttribute('assignment_id') || isset($before['assignment_id']))) {
            $assignmentId = $model->assignment_id ?? ($before['assignment_id'] ?? null);
            $linkedAssignment = $assignmentId ? \App\Models\DriverAssignment::withTrashed()->find($assignmentId) : null;
            $bookingId = (string) ($linkedAssignment?->booking_id ?? '');
        }
        if ($model->getAttribute('booking_qc_id') || isset($before['booking_qc_id'])) {
            $qcId = $model->booking_qc_id ?? ($before['booking_qc_id'] ?? null);
            $linkedQc = $qcId ? \App\Models\Booking\BookingQC::withTrashed()->find($qcId) : null;
            if ($bookingId === '') {
                $bookingId = (string) ($linkedQc?->booking_id ?? '');
            }
        }
        if ($model instanceof \App\Models\Vehicle\VehicleMaintenanceRecord) {
            $scheduleId = $model->schedule_id ?? ($before['schedule_id'] ?? null);
            $linkedMaintenanceSchedule = $scheduleId
                ? \App\Models\Vehicle\VehicleMaintenanceSchedule::withTrashed()->find($scheduleId)
                : null;
            if ($bookingId === '') $bookingId = (string) ($linkedMaintenanceSchedule?->booking_id ?? '');
        }
        $bookingId = (string) request()->attributes->get('booking_history_booking_id', $bookingId);
        if ($bookingId === '') return;
        $bookingItemId = request()->attributes->has('booking_history_booking_item_id')
            ? request()->attributes->get('booking_history_booking_item_id')
            : ($model instanceof \App\Models\Booking\BookingItem
                ? (string) $model->getKey()
                : ($model->getAttribute('booking_item_id')
                    ? (string) $model->getAttribute('booking_item_id')
                    : ($linkedAssignment?->booking_item_id
                        ? (string) $linkedAssignment->booking_item_id
                        : ($linkedQc?->booking_item_id
                            ? (string) $linkedQc->booking_item_id
                            : ($linkedMaintenanceSchedule?->booking_item_id
                                ? (string) $linkedMaintenanceSchedule->booking_item_id
                                : null)))));

        $trackedFields = self::TRACKED_FIELDS;
        if ($model instanceof \App\Models\Inquiry) {
            $trackedFields = array_merge($trackedFields, [
                'name', 'email', 'phone', 'message', 'response', 'form_data', 'search_context', 'ip_address', 'user_agent',
            ]);
        } elseif ($model instanceof \App\Models\PhoneCall) {
            $trackedFields = array_merge($trackedFields, ['phone', 'client_name', 'summary']);
        }

        $changes = [];
        foreach ($trackedFields as $field) {
            if (array_key_exists($field, $before) || array_key_exists($field, $model->getAttributes())) {
                $old = $before[$field] ?? null;
                $new = $model->getAttribute($field);
                if (in_array($operation, ['created', 'deleted', 'restored'], true) || $old !== $new) {
                    if ($model instanceof \App\Models\Inquiry && in_array($field, ['customer_id', 'booking_id'], true)) {
                        $changes[$field] = [
                            'from' => $operation === 'created' || $operation === 'restored' ? null : $this->safeValue($old),
                            'to' => $operation === 'deleted' ? null : $this->safeValue($new),
                        ];
                        continue;
                    }
                    $redact = in_array($field, [
                        'special_requirements', 'emergency_contact_name', 'emergency_contact_phone', 'customer_name',
                        'emergency_contact_relationship', 'details', 'context', 'workflow_data', 'conditions', 'notes',
                        'comments', 'justification', 'approval_justification', 'review_notes',
                        'description', 'internal_notes', 'payment_notes', 'approval_notes', 'repair_notes', 'qc_notes', 'photos',
                        'corporate_approval_note', 'customer_feedback', 'customer_feedback_response', 'driver_feedback',
                        'confirmation_notes', 'pickup_location', 'pickup_latitude', 'pickup_longitude', 'current_latitude', 'current_longitude', 'location_updated_at',
                        'dropoff_location', 'dropoff_latitude', 'dropoff_longitude', 'address', 'location',
                        'latitude', 'longitude', 'start_latitude', 'start_longitude', 'end_latitude', 'end_longitude',
                        'accept_latitude', 'accept_longitude', 'final_latitude',
                        'final_longitude', 'pickup_arrival_latitude', 'pickup_arrival_longitude',
                        'trip_start_latitude', 'trip_start_longitude', 'driver_name_snapshot',
                        'assigned_by_name_snapshot', 'confirmed_by_name_snapshot', 'notes', 'request_payload_checksum',
                    ], true);
                    if (($model instanceof \App\Models\Inquiry && in_array($field, [
                        'message', 'response', 'name', 'email', 'phone', 'form_data', 'search_context', 'ip_address', 'user_agent',
                    ], true)) || ($model instanceof \App\Models\PhoneCall && in_array($field, [
                        'phone', 'client_name', 'summary',
                    ], true))) {
                        $redact = true;
                    }
                    $changes[$field] = [
                        'from' => $redact
                            ? ($operation === 'created' || $operation === 'restored' || $old === null ? null : '[redacted]')
                            : ($operation === 'created' || $operation === 'restored' ? null : $this->safeValue($old)),
                        'to' => $redact
                            ? ($operation === 'deleted' || $new === null ? null : '[redacted]')
                            : ($operation === 'deleted' ? null : $this->safeValue($new)),
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
            $trackedFields,
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
                ?? ($operation === 'created' && $model instanceof \App\Models\Booking\BookingCollectionWorkItemFollowUp ? $model->getAttribute('actor_user_id') : null)
                ?? ($model instanceof \App\Models\Booking\BookingPaymentScheduleRevision ? $model->getAttribute('approved_by') : null)
                ?? ($model instanceof \App\Models\Booking\BookingDispatch && $model->getAttribute('returned_by') !== ($before['returned_by'] ?? null) ? $model->getAttribute('returned_by') : null)
                ?? ($model instanceof \App\Models\Booking\BookingDispatch && $model->getAttribute('dispatched_by') !== ($before['dispatched_by'] ?? null) ? $model->getAttribute('dispatched_by') : null)
                ?? $model->getAttribute('resolved_by')
                ?? ($before['updated_user_id'] ?? null);
            $actor = $actorId ? User::query()->find($actorId) : null;
        }
        $requestReason = $reasonOverride;
        if ($requestReason === null && $request->attributes->has('booking_history_reason')) {
            $requestReason = $request->attributes->get('booking_history_reason');
        } elseif ($requestReason === null) {
            $requestReason = $model->getAttribute('change_reason')
                ?? $request->input('change_reason')
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
                ?? $request->input('reason');
        }
        $reason = is_string($requestReason) ? trim($requestReason) : null;
        $id = (string) Str::uuid();
        $now = now()->utc();
        DB::table('booking_history_events')->insert([
            'id' => $id,
            'booking_id' => $bookingId,
            'booking_item_id' => $bookingItemId,
            'entity_type' => $model instanceof \App\Models\Vehicle\VehiclePricing\BookingPriceAdjustmentHistory
                ? 'VehicleBookingPriceAdjustmentHistory'
                : class_basename($model),
            'entity_id' => (string) $model->getKey(),
            'event_type' => $this->eventType($model, $operation, $changes),
            'actor_type' => $actor ? 'user' : ($model instanceof \App\Models\IntegrationWebhookEvent ? 'integration' : 'system'),
            'actor_user_id' => $actor?->getAuthIdentifier(),
            'actor_display_snapshot' => $actor
                ? trim((string) ($actor->full_name ?? $actor->name ?? $actor->email))
                : ($model instanceof \App\Models\IntegrationWebhookEvent ? 'Integration: ' . $model->provider : 'System'),
            'source' => Str::limit($request->route()?->getName() ?: ($request->route()?->uri() ?: $request->attributes->get('booking_history_source_trigger') ?: (app()->runningInConsole() ? 'console' : 'application')), 100),
            'reason' => $reason !== '' ? Str::limit($reason, 1000) : null,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'context' => json_encode(array_filter([
                'operation' => $operation,
                'booking_number' => $model instanceof \App\Models\Booking\Booking
                    ? $model->booking_number
                    : null,
                'item_code' => $model instanceof \App\Models\Booking\BookingItem
                    ? $model->item_code
                    : null,
                'source_trigger' => $request->attributes->get('booking_history_source_trigger'),
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
        if ($model instanceof \App\Models\Booking\Booking && isset($changes['responsible_user_id'])) return 'booking_responsible_user_changed';
        if ($model instanceof \App\Models\Booking\Booking && $operation === 'created') return 'booking_created';
        if ($model instanceof \App\Models\Booking\BookingItem && in_array($operation, ['created', 'deleted', 'restored'], true)) {
            return 'booking_item_' . $operation;
        }
        if ($model instanceof \App\Models\Vehicle\Vehicle && (isset($changes['availability_status']) || isset($changes['current_mileage']))) {
            return 'booking_vehicle_state_changed';
        }
        if ($model instanceof BookingStatus) return 'booking_status_record_' . $operation;
        if (isset($changes['trip_phase'])) return 'booking_trip_phase_changed';
        if ($model instanceof \App\Models\Driver\DriverTrackingIncident) return 'booking_tracking_incident_' . (isset($changes['state']) ? 'state_changed' : $operation);
        if ($model instanceof \App\Models\Driver\DriverLog) return 'booking_driver_log_' . (isset($changes['status']) ? 'status_changed' : $operation);
        if ($model instanceof \App\Models\WaitingTimeRecord) return 'booking_waiting_time_' . (isset($changes['duration_seconds']) ? 'period_closed' : $operation);
        if ($model instanceof \App\Models\Driver\DriverIouAdvance) return 'booking_driver_iou_' . $operation;
        if ($model instanceof \App\Models\Vehicle\VehicleMaintenanceSchedule) return 'booking_maintenance_schedule_' . (isset($changes['status']) ? 'status_changed' : $operation);
        if ($model instanceof \App\Models\Vehicle\VehicleMaintenanceRecord) return 'booking_maintenance_record_' . (isset($changes['status']) ? 'status_changed' : $operation);
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
        if ($model instanceof \App\Models\Booking\BookingCollectionWorkItemFollowUp) return 'booking_collection_follow_up_recorded';
        if ($model instanceof \App\Models\Booking\BookingPaymentScheduleRuleEvent) return 'collection_schedule_rule_changed';
        if ($model instanceof \App\Models\Booking\BookingPaymentScheduleRevision) return 'collection_schedule_revised';
        if ($model instanceof \App\Models\Booking\BookingPaymentReceiptComponent) return 'booking_payment_receipt_component_' . $operation;
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
