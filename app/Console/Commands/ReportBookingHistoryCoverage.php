<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportBookingHistoryCoverage extends Command
{
    protected $signature = 'booking-history:coverage';

    protected $description = 'Report recorded booking-history coverage by source and event type.';

    private const REQUIRED_PARITY_CHECKS = 74;

    private ?string $verifiedCoverageStart = null;

    public function handle(): int
    {
        if (!Schema::hasTable('booking_history_events')) {
            $this->error('Booking history ledger is not installed. Run the reviewed migrations before requesting coverage.');
            return self::FAILURE;
        }

        $totals = DB::table('booking_history_events')
            ->selectRaw('COUNT(*) AS total_events')
            ->selectRaw("SUM(CASE WHEN provenance = 'live' THEN 1 ELSE 0 END) AS live_events")
            ->selectRaw("SUM(CASE WHEN provenance = 'reconstructed' THEN 1 ELSE 0 END) AS reconstructed_events")
            ->selectRaw('SUM(CASE WHEN actor_user_id IS NULL THEN 1 ELSE 0 END) AS events_without_user_actor')
            ->selectRaw('SUM(CASE WHEN idempotency_key IS NULL THEN 1 ELSE 0 END) AS events_without_idempotency_key')
            ->first();

        $groups = DB::table('booking_history_events')
            ->select(['event_type', 'source', 'provenance'])
            ->selectRaw('COUNT(*) AS event_count')
            ->groupBy('event_type', 'source', 'provenance')
            ->orderBy('source')->orderBy('event_type')->orderBy('provenance')
            ->get()
            ->map(fn (object $row): array => [
                $row->event_type,
                $row->source ?: '(unknown)',
                $row->provenance,
                (int) $row->event_count,
            ]);

        $duplicateIdempotencyKeys = DB::query()->fromSub(
            DB::table('booking_history_events')
                ->whereNotNull('idempotency_key')
                ->select('idempotency_key')
                ->groupBy('idempotency_key')
                ->havingRaw('COUNT(*) > 1'),
            'duplicate_keys',
        )->count();

        $configuredCoverageStart = config('booking_history.coverage_start');
        $this->verifiedCoverageStart = $this->normalizeCoverageStart($configuredCoverageStart);
        $sourceParity = collect([
            $this->sourceParity('booking_statuses', [['prefix' => 'booking-status:', 'source' => 'booking_statuses', 'event_type' => 'booking_status_changed']]),
            $this->bookingStatusRecordMutationParity(),
            $this->modelStateParity('bookings', 'Booking'),
            $this->modelStateParity('booking_items', 'BookingItem'),
            $this->modelStateParity('vehicle_assignments', 'VehicleAssignment'),
            $this->modelStateParity('booking_price_adjustment_history', 'VehicleBookingPriceAdjustmentHistory'),
            $this->modelStateParity('driver_assignments', 'DriverAssignment'),
            $this->modelStateParity('driver_sessions', 'DriverSession', bookingRelationTable: 'driver_assignments', bookingRelationForeignKey: 'assignment_id'),
            $this->modelStateParity('phone_calls', 'PhoneCall'),
            $this->modelStateParity('integration_webhook_events', 'IntegrationWebhookEvent', 'linked_booking_id'),
            $this->approvalDecisionParity(),
            $this->approvalDecisionTableParity(),
            $this->paymentTransactionParity(),
            $this->sourceParity('financial_audit_events', [['prefix' => 'financial-audit:', 'source' => 'financial_audit_events']]),
            $this->sourceParity('booking_activities', [
                ['prefix' => 'booking-activity:', 'source' => 'booking_activities', 'event_type' => 'trip_price_changed'],
                ['prefix' => 'legacy:booking-price:', 'source' => 'legacy:booking_activities', 'event_type' => 'booking_price_changed'],
            ], 'trip_price_changed'),
            $this->bookingInquiryParity(),
            $this->postTripMaintenanceParity(),
            $this->salesAttributionParity(),
            $this->modelStateParity('booking_addons', 'BookingAddon'),
            $this->modelStateParity('booking_variable_customizations', 'BookingVariableCustomization'),
            $this->modelStateParity('booking_dispatches', 'BookingDispatch'),
            $this->modelStateParity('booking_qcs', 'BookingQC'),
            $this->modelStateParity('booking_extension_requests', 'BookingExtensionRequest'),
            $this->modelStateParity('booking_payment_receipts', 'BookingPaymentReceipt'),
            $this->modelStateParity('booking_payment_receipt_components', 'BookingPaymentReceiptComponent', bookingRelationTable: 'booking_payment_receipts', bookingRelationForeignKey: 'receipt_id'),
            $this->modelStateParity('booking_payment_schedules', 'BookingPaymentSchedule'),
            $this->modelStateParity('booking_payment_schedule_allocations', 'BookingPaymentScheduleAllocation', bookingRelationTable: 'booking_payment_schedules', bookingRelationForeignKey: 'booking_payment_schedule_id'),
            $this->modelStateParity('booking_payment_schedule_rules', 'BookingPaymentScheduleRule'),
            $this->modelStateParity('booking_payment_schedule_rule_events', 'BookingPaymentScheduleRuleEvent', bookingRelationTable: 'booking_payment_schedule_rules', bookingRelationForeignKey: 'booking_payment_schedule_rule_id'),
            $this->modelStateParity('booking_payment_adjustments', 'BookingPaymentAdjustment'),
            $this->modelStateParity('booking_discounts', 'BookingDiscount'),
            $this->modelStateParity('booking_deposit_refunds', 'BookingDepositRefund'),
            $this->modelStateParity('booking_collection_commissions', 'BookingCollectionCommission'),
            $this->modelStateParity('booking_collection_work_items', 'BookingCollectionWorkItem'),
            $this->modelStateParity('booking_collection_work_item_follow_ups', 'BookingCollectionWorkItemFollowUp'),
            $this->modelStateParity('booking_collection_submissions', 'BookingCollectionSubmission'),
            $this->modelStateParity('booking_commercial_value_adjustments', 'BookingCommercialValueAdjustment'),
            $this->modelStateParity('booking_terms', 'BookingTerm'),
            $this->modelStateParity('booking_pricings', 'BookingPricing'),
            $this->modelStateParity('booking_common_rate_pricings', 'BookingCommonRatePricing'),
            $this->modelStateParity('agent_commissions', 'AgentCommission'),
            $this->modelStateParity('booking_approvals', 'BookingApproval'),
            $this->modelStateParity('booking_collection_reminder_deliveries', 'BookingCollectionReminderDelivery'),
            $this->modelStateParity('booking_customer_mobile_activities', 'BookingCustomerMobileActivity'),
            $this->modelStateParity('booking_payment_receipt_finality_events', 'BookingPaymentReceiptFinalityEvent'),
            $this->modelStateParity('booking_payment_schedule_revisions', 'BookingPaymentScheduleRevision'),
            $this->modelStateParity('corporate_transport_generation_logs', 'CorporateTransportGenerationLog'),
            $this->modelStateParity('corporate_transport_participations', 'CorporateTransportParticipation'),
            $this->modelStateParity('driver_assignment_stops', 'DriverAssignmentStop'),
            $this->modelStateParity('driver_logs', 'DriverLog'),
            $this->modelStateParity('driver_tracking_incidents', 'DriverTrackingIncident'),
            $this->modelStateParity('vehicle_maintenance_schedules', 'VehicleMaintenanceSchedule'),
            $this->maintenanceRecordParity(),
            $this->waitingTimeRecordParity(),
            $this->modelStateParity('driver_cash_settlement_items', 'DriverCashSettlementItem'),
            $this->modelStateParity('driver_hire_settlements', 'DriverHireSettlement'),
            $this->modelStateParity('driver_iou_advances', 'DriverIouAdvance'),
            $this->modelStateParity('financial_adjustments', 'FinancialAdjustment'),
            $this->modelStateParity('financial_payment_allocations', 'FinancialPaymentAllocation'),
            $this->modelStateParity('financial_settlement_items', 'FinancialSettlementItem'),
            $this->modelStateParity('invoices', 'Invoice'),
            $this->modelStateParity('loyalty_point_transactions', 'LoyaltyPointTransaction'),
            $this->modelStateParity('payment_events', 'PaymentEvent'),
            $this->modelStateParity('pending_payment_links', 'PendingPaymentLink'),
            $this->modelStateParity('promo_code_usages', 'PromoCodeUsage'),
            $this->modelStateParity('sales_activities', 'SalesActivity'),
            $this->modelStateParity('sales_attribution_exceptions', 'SalesAttributionException'),
            $this->modelStateParity('sales_booking_attributions', 'SalesBookingAttribution'),
            $this->modelStateParity('sales_commission_decisions', 'SalesCommissionDecision'),
            $this->modelStateParity('sales_tasks', 'SalesTask'),
            $this->modelStateParity('sales_opportunities', 'SalesOpportunity', 'won_booking_id', false),
            $this->modelStateParity('inquiries', 'Inquiry'),
            $this->bookingWorkflowActivityParity(),
            $this->vehicleEventLinkParity(),
        ])->filter()->values();
        $unmatchedSourceRows = $sourceParity->sum(fn (array $row): int => (int) $row[3]);
        $executedParityChecks = $sourceParity->count();
        $checksPass = $this->verifiedCoverageStart !== null
            && $executedParityChecks === self::REQUIRED_PARITY_CHECKS
            && $unmatchedSourceRows === 0
            && $duplicateIdempotencyKeys === 0;

        $this->table(['Measure', 'Count'], [
            ['Coverage start', $this->verifiedCoverageStart ?? (is_string($configuredCoverageStart) && trim($configuredCoverageStart) !== '' ? $configuredCoverageStart . ' (invalid)' : 'unknown')],
            ['Total events', (int) ($totals->total_events ?? 0)],
            ['Live events', (int) ($totals->live_events ?? 0)],
            ['Reconstructed events', (int) ($totals->reconstructed_events ?? 0)],
            ['Events without user actor', (int) ($totals->events_without_user_actor ?? 0)],
            ['Events without idempotency key', (int) ($totals->events_without_idempotency_key ?? 0)],
            ['Duplicate idempotency keys', $duplicateIdempotencyKeys],
            ['Source parity checks executed', $executedParityChecks . ' / ' . self::REQUIRED_PARITY_CHECKS],
        ]);
        $this->table(['Event type', 'Source', 'Provenance', 'Count'], $groups->all());
        $this->table(['Verifiable source rows currently present', 'Rows', 'Matched ledger rows', 'Unmatched rows'], $sourceParity->all());
        $this->line('Source-row parity covers only the listed retained records; it does not prove complete historical capture. Failed capture attempts are not retained.');
        if (!$checksPass) {
            $this->error('Coverage checks are incomplete or failing: configure a verified coverage start, execute all source parity checks, and resolve unmatched rows or duplicate idempotency keys.');
        }

        return $checksPass ? self::SUCCESS : self::FAILURE;
    }

    private function normalizeCoverageStart(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        if (!preg_match('/(?:Z|\\+00(?::?00|00)?)$/i', trim($value))) return null;

        try {
            $timestamp = CarbonImmutable::parse($value);
            if ($timestamp->offset !== 0 || $timestamp->isFuture()) return null;

            return $timestamp->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function sourceParity(
        string $table,
        array $ledgerIdentities,
        ?string $sourceEventKey = null,
        ?string $dateColumn = null,
        ?string $since = null,
    ): ?array {
        if (!Schema::hasTable($table)) return null;

        $source = DB::table($table . ' as source')->whereNotNull('source.booking_id');
        if ($sourceEventKey !== null) $source->where('source.event_key', $sourceEventKey);
        if ($dateColumn !== null && $since !== null) $source->where('source.' . $dateColumn, '>=', $since);
        $sourceRows = (clone $source)->count();
        $matched = (clone $source)->whereExists(function ($query) use ($ledgerIdentities): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->where(function ($matches) use ($ledgerIdentities): void {
                    foreach ($ledgerIdentities as $identity) {
                        $matches->orWhere(function ($match) use ($identity): void {
                            $match->where('history.source', $identity['source'])
                                ->when($identity['event_type'] ?? null, fn ($match, $eventType) => $match->where('history.event_type', $eventType))
                                ->whereRaw($this->sourceIdempotencyMatchSql($identity['prefix']));
                        });
                    }
                });
        })->count();

        return [$table, $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function sourceIdempotencyMatchSql(string $keyPrefix): string
    {
        $driver = DB::connection()->getDriverName();
        $id = match ($driver) {
            'pgsql' => "'" . $keyPrefix . "' || CAST(source.id AS TEXT)",
            'mysql', 'mariadb' => "CONCAT('" . $keyPrefix . "', CAST(source.id AS CHAR))",
            'sqlsrv' => "CONCAT('" . $keyPrefix . "', CONVERT(varchar(100), source.id))",
            default => "'" . $keyPrefix . "' || CAST(source.id AS TEXT)",
        };

        return 'history.idempotency_key = ' . $id;
    }

    private function salesAttributionParity(): ?array
    {
        if (!Schema::hasTable('sales_booking_attribution_events') || !Schema::hasTable('sales_booking_attributions')) return null;

        $source = DB::table('sales_booking_attribution_events as source')
            ->join('sales_booking_attributions as attribution', 'attribution.id', '=', 'source.attribution_id')
            ->whereNotNull('attribution.booking_id');
        $sourceRows = (clone $source)->count();
        $matched = (clone $source)->whereExists(function ($query): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->where('history.source', 'sales_booking_attribution_events')
                ->whereRaw($this->sourceIdempotencyMatchSql('sales-attribution-event:'));
        })->count();

        return ['sales_booking_attribution_events', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function bookingStatusRecordMutationParity(): ?array
    {
        $coverageStart = $this->verifiedCoverageStart;
        if (!Schema::hasTable('booking_statuses') || !$coverageStart) return null;

        $source = DB::table('booking_statuses as source')
            ->whereNotNull('source.booking_id')
            ->where('source.updated_at', '>=', $coverageStart)
            ->where(function ($query): void {
                // shortcut: second-precision timestamps can hide same-second edits; add a revision counter if exact mutation parity is required.
                $query->whereColumn('source.updated_at', '>', 'source.created_at')
                    ->orWhereNotNull('source.deleted_at');
            });
        $sourceRows = (clone $source)->count();
        $matched = (clone $source)->whereExists(function ($query) use ($coverageStart): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->whereColumn('history.booking_id', 'source.booking_id')
                ->whereColumn('history.entity_id', 'source.id')
                ->where('history.entity_type', 'BookingStatus')
                ->whereIn('history.event_type', [
                    'booking_status_record_updated', 'booking_status_record_deleted', 'booking_status_record_restored',
                ])
                ->where('history.occurred_at', '>=', $coverageStart)
                ->whereColumn('history.occurred_at', '>=', 'source.updated_at');
        })->count();

        return ['booking status record edits/deletes/restores since coverage start', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function modelStateParity(
        string $table,
        string $entityType,
        string $bookingLinkColumn = 'booking_id',
        bool $checkCreation = true,
        ?string $bookingRelationTable = null,
        ?string $bookingRelationForeignKey = null,
    ): ?array
    {
        $coverageStart = $this->verifiedCoverageStart;
        $hasBookingRelation = $bookingRelationTable !== null && $bookingRelationForeignKey !== null;
        if (!Schema::hasTable($table) || !Schema::hasTable('booking_items') || !$coverageStart
            || !Schema::hasColumn($table, 'created_at') || !Schema::hasColumn($table, 'updated_at')
            || ($hasBookingRelation
                ? !Schema::hasTable($bookingRelationTable) || !Schema::hasColumn($table, $bookingRelationForeignKey) || !Schema::hasColumn($bookingRelationTable, 'booking_id')
                : ($table === 'bookings' ? $bookingLinkColumn !== 'booking_id' : !Schema::hasColumn($table, $bookingLinkColumn)))
            || ($table === 'vehicle_assignments' && !Schema::hasColumn($table, 'booking_item_id'))) return null;

        $base = DB::table($table . ' as source');
        if ($hasBookingRelation) {
            $base->join($bookingRelationTable . ' as booking_link', 'booking_link.id', '=', 'source.' . $bookingRelationForeignKey);
        } elseif ($table !== 'bookings') {
            $base->whereNotNull('source.' . $bookingLinkColumn);
        }
        $bookingColumn = $hasBookingRelation ? 'booking_link.booking_id' : ($table === 'bookings' ? 'source.id' : 'source.' . $bookingLinkColumn);
        $createdRows = $createdMatched = 0;
        if ($checkCreation) {
            $created = (clone $base)->where('source.created_at', '>=', $coverageStart);
            $createdRows = (clone $created)->count();
            $createdMatched = (clone $created)->whereExists(function ($query) use ($bookingColumn, $entityType, $coverageStart): void {
                $query->selectRaw('1')->from('booking_history_events as history')
                    ->whereColumn('history.booking_id', $bookingColumn)
                    ->whereColumn('history.entity_id', 'source.id')
                    ->where('history.entity_type', $entityType)
                    ->where('history.occurred_at', '>=', $coverageStart)
                    ->whereColumn('history.occurred_at', '>=', 'source.created_at')
                    ->whereRaw($this->historyOperationMatchSql('created'));
                $this->requireHistoryItemBelongsToBooking($query);
                $this->requireVehicleAssignmentItemMatch($query, $table);
            })->count();
        }
        // shortcut: second-precision timestamps can hide same-second edits and only the latest row state is checked; add immutable mutation records to verify every change.
        $mutations = (clone $base)->where('source.updated_at', '>=', $coverageStart)
            ->where(function ($query) use ($table): void {
                $query->whereColumn('source.updated_at', '>', 'source.created_at');
                if (Schema::hasColumn($table, 'deleted_at')) {
                    $query->orWhere('source.deleted_at', '>=', $this->verifiedCoverageStart);
                }
            });
        $mutationRows = (clone $mutations)->count();
        $mutationMatched = (clone $mutations)->whereExists(function ($query) use ($bookingColumn, $entityType, $coverageStart): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->whereColumn('history.booking_id', $bookingColumn)
                ->whereColumn('history.entity_id', 'source.id')
                ->where('history.entity_type', $entityType)
                ->where('history.occurred_at', '>=', $coverageStart)
                ->whereColumn('history.occurred_at', '>=', 'source.updated_at')
                ->whereRaw($this->historyOperationMatchSql(['updated', 'deleted', 'restored']));
            $this->requireHistoryItemBelongsToBooking($query);
            $this->requireVehicleAssignmentItemMatch($query, $table);
        })->count();
        $sourceRows = $createdRows + $mutationRows;
        $matched = $createdMatched + $mutationMatched;

        return [$table . ($checkCreation ? ' created/latest mutation states' : ' latest linked mutation states') . ' since coverage start', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function requireHistoryItemBelongsToBooking($query): void
    {
        $query->where(function ($link): void {
            $link->whereNull('history.booking_item_id')
                ->orWhereExists(function ($item): void {
                    $item->selectRaw('1')->from('booking_items as booking_item')
                        ->whereColumn('booking_item.id', 'history.booking_item_id')
                        ->whereColumn('booking_item.booking_id', 'history.booking_id');
                });
        });
    }

    private function requireVehicleAssignmentItemMatch($query, string $table): void
    {
        if ($table !== 'vehicle_assignments') return;

        $query->where(function ($link): void {
            $link->where(function ($bothNull): void {
                $bothNull->whereNull('source.booking_item_id')->whereNull('history.booking_item_id');
            })->orWhereColumn('history.booking_item_id', 'source.booking_item_id');
        });
    }

    private function historyOperationMatchSql(array|string $operations): string
    {
        $operations = array_map(static fn (string $operation): string => "'" . str_replace("'", "''", $operation) . "'", (array) $operations);
        $expected = count($operations) === 1 ? $operations[0] : '(' . implode(', ', $operations) . ')';
        $operator = count($operations) === 1 ? '=' : 'IN';

        $expression = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(history.context, '$.operation'))",
            'pgsql' => "history.context->>'operation'",
            'sqlsrv' => "JSON_VALUE(history.context, '$.operation')",
            default => "json_extract(history.context, '$.operation')",
        };

        return $expression . ' ' . $operator . ' ' . $expected;
    }

    private function approvalDecisionParity(): ?array
    {
        if (!Schema::hasTable('booking_approvals')) return null;

        $sourceRows = 0;
        $matched = 0;
        DB::table('booking_approvals')->whereNotNull('booking_id')->orderBy('id')->chunkById(200, function ($approvals) use (&$sourceRows, &$matched): void {
            $keys = [];
            foreach ($approvals as $approval) {
                $conditions = is_array($approval->conditions)
                    ? $approval->conditions
                    : (json_decode((string) ($approval->conditions ?? ''), true) ?: []);
                $decisions = is_array($conditions['step_decisions'] ?? null) ? $conditions['step_decisions'] : [];
                foreach ($decisions as $decision) {
                    if (empty($decision['user_id']) || empty($decision['decided_at'])) continue;
                    $step = max(1, (int) ($decision['step'] ?? 1));
                    $keys[] = 'booking-approval-decision:' . $approval->id . ':' . $step . ':' . $decision['user_id'];
                }

                $status = (string) ($approval->status ?? '');
                $decidedAt = $status === 'rejected' ? $approval->rejected_at : ($status === 'approved' ? $approval->approved_at : null);
                $approverId = $approval->approver_id;
                if (!in_array($status, ['approved', 'rejected'], true) || !$decidedAt || !$approverId) continue;
                if ($status === 'approved' && collect($decisions)->contains(fn ($decision) => (string) ($decision['user_id'] ?? '') === (string) $approverId)) continue;
                $step = max(1, count($decisions) + 1);
                $keys[] = 'booking-approval-decision:' . $approval->id . ':' . $step . ':' . $approverId;
            }

            $keys = array_values(array_unique($keys));
            $sourceRows += count($keys);
            if ($keys) {
                $matched += DB::table('booking_history_events')->whereIn('idempotency_key', $keys)->count();
            }
        });

        return ['verifiable booking approval decisions', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function approvalDecisionTableParity(): ?array
    {
        $coverageStart = $this->verifiedCoverageStart;
        if (!Schema::hasTable('booking_approval_decisions')) {
            return ['legacy booking_approval_decisions (table absent)', 0, 0, 0];
        }
        if (!Schema::hasTable('booking_approvals') || !$coverageStart) return null;

        $source = DB::table('booking_approval_decisions as source')
            ->join('booking_approvals as approval', 'approval.id', '=', 'source.booking_approval_id')
            ->whereNotNull('approval.booking_id')
            ->where('source.decided_at', '>=', $coverageStart);
        $sourceRows = (clone $source)->count();
        $matched = (clone $source)->whereExists(function ($query): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->whereColumn('history.booking_id', 'approval.booking_id')
                ->where('history.source', 'booking_approval_decisions')
                ->where('history.entity_type', 'BookingApprovalDecision')
                ->whereRaw($this->approvalDecisionIdempotencyMatchSql());
        })->count();

        return ['booking approval decision rows since coverage start', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function approvalDecisionIdempotencyMatchSql(): string
    {
        $driver = DB::connection()->getDriverName();
        $key = match ($driver) {
            'mysql', 'mariadb' => "CONCAT('booking-approval-decision:', CAST(source.booking_approval_id AS CHAR), ':', CAST(source.step AS CHAR), ':', CAST(source.user_id AS CHAR))",
            'sqlsrv' => "CONCAT('booking-approval-decision:', CONVERT(varchar(100), source.booking_approval_id), ':', CONVERT(varchar(20), source.step), ':', CONVERT(varchar(100), source.user_id))",
            default => "'booking-approval-decision:' || CAST(source.booking_approval_id AS TEXT) || ':' || CAST(source.step AS TEXT) || ':' || CAST(source.user_id AS TEXT)",
        };

        return 'history.idempotency_key = ' . $key;
    }

    private function paymentTransactionParity(): ?array
    {
        $coverageStart = $this->verifiedCoverageStart;
        if (!Schema::hasTable('payment_transactions') || !$coverageStart) return null;

        $sourceRows = 0;
        $matched = 0;
        DB::table('payment_transactions')->whereNotNull('booking_id')->where('created_at', '>=', $coverageStart)
            ->orderBy('id')->chunkById(200, function ($rows) use (&$sourceRows, &$matched): void {
                foreach ($rows as $transaction) {
                    $keys = [
                        'payment-transaction-status:' . $transaction->id . ':pending',
                        'payment-transaction-gateway-session:' . $transaction->id,
                    ];
                    if (in_array($transaction->status, ['success', 'failed', 'cancelled'], true)) {
                        $keys[] = 'payment-transaction-status:' . $transaction->id . ':' . $transaction->status;
                    }
                    $sourceRows += count($keys);
                    $matched += DB::table('booking_history_events')->whereIn('idempotency_key', $keys)->count();
                }
            });

        return ['payment transaction lifecycle events since coverage start', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function postTripMaintenanceParity(): ?array
    {
        $coverageStart = $this->verifiedCoverageStart;
        if (!Schema::hasTable('booking_activities') || !$coverageStart) return null;

        $expected = 0;
        $matched = 0;
        DB::table('booking_activities')->whereNotNull('booking_id')->where('event_key', 'post_trip_maintenance')
            ->where(function ($query) use ($coverageStart): void {
                $query->where('created_at', '>=', $coverageStart)->orWhere('updated_at', '>=', $coverageStart);
            })->orderBy('id')->chunkById(200, function ($activities) use ($coverageStart, &$expected, &$matched): void {
                foreach ($activities as $activity) {
                    $states = [];
                    if ($activity->created_at && $activity->created_at >= $coverageStart) $states[] = 'pending';
                    if ($activity->result_status === 'completed' && $activity->updated_at && $activity->updated_at >= $coverageStart) {
                        $states[] = 'completed';
                    }
                    foreach (array_unique($states) as $state) {
                        $expected++;
                        $matched += DB::table('booking_history_events')
                            ->where('source', 'booking_activities')
                            ->where('event_type', 'post_trip_maintenance')
                            ->where('idempotency_key', 'booking-activity:' . $activity->id . ':' . $state)
                            ->exists() ? 1 : 0;
                    }
                }
            });

        return ['post-trip maintenance transitions since coverage start', $expected, $matched, max(0, $expected - $matched)];
    }

    private function bookingInquiryParity(): ?array
    {
        $coverageStart = $this->verifiedCoverageStart;
        if (!$coverageStart || !Schema::hasTable('booking_activities')) return null;

        return $this->sourceParity('booking_activities', [[
            'prefix' => 'booking-activity:',
            'source' => 'booking_activities',
            'event_type' => 'booking_inquiry_created',
        ]], 'booking_inquiry_created', 'event_at', $coverageStart);
    }

    private function bookingWorkflowActivityParity(): ?array
    {
        $coverageStart = $this->verifiedCoverageStart;
        if (!$coverageStart || !Schema::hasTable('booking_activities')) return null;

        $eventKeys = [
            'booking_updated', 'booking_vehicle_changed', 'resource_swap_completed',
            'booking_responsible_user_changed', 'rental_return_no_show',
            'replacement_requested', 'replacement_request_closed',
            'rental_extension_requested', 'rental_extension_approved', 'rental_extension_rejected',
            'additional_rental_charge_added',
        ];
        $source = DB::table('booking_activities as source')
            ->whereNotNull('source.booking_id')
            ->whereIn('source.event_key', $eventKeys)
            ->where('source.created_at', '>=', $coverageStart);
        $sourceRows = (clone $source)->count();
        $matched = (clone $source)->whereExists(function ($query): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->whereColumn('history.booking_id', 'source.booking_id')
                ->where('history.source', 'booking_activities')
                ->whereColumn('history.event_type', 'source.event_key')
                ->whereRaw($this->sourceIdempotencyMatchSql('booking-activity:'));
        })->count();

        return ['booking workflow activity events since coverage start', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function vehicleEventLinkParity(): ?array
    {
        $coverageStart = $this->verifiedCoverageStart;
        if (!$coverageStart || !Schema::hasTable('vehicles')) return null;

        $source = DB::table('booking_history_events as history')
            ->where('history.entity_type', 'Vehicle')
            ->where('history.event_type', 'booking_vehicle_state_changed')
            ->where('history.occurred_at', '>=', $coverageStart);
        $sourceRows = (clone $source)->count();
        $matched = (clone $source)->whereExists(function ($query): void {
            $query->selectRaw('1')->from('vehicles as vehicle')
                ->whereColumn('vehicle.id', 'history.entity_id');
        })->whereExists(function ($query): void {
            $query->selectRaw('1')->from('bookings as booking')
                ->whereColumn('booking.id', 'history.booking_id');
        })->where(function ($query): void {
            $query->whereNull('history.booking_item_id')
                ->orWhereExists(function ($item): void {
                    $item->selectRaw('1')->from('booking_items as booking_item')
                        ->whereColumn('booking_item.id', 'history.booking_item_id')
                        ->whereColumn('booking_item.booking_id', 'history.booking_id');
                });
        })->count();

        return ['vehicle workflow event entity/booking/item links since coverage start', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function maintenanceRecordParity(): ?array
    {
        $coverageStart = $this->verifiedCoverageStart;
        if (!$coverageStart || !Schema::hasTable('vehicle_maintenance_records')
            || !Schema::hasTable('vehicle_maintenance_schedules')) return null;

        $base = DB::table('vehicle_maintenance_records as source')
            ->join('vehicle_maintenance_schedules as schedule', 'schedule.id', '=', 'source.schedule_id')
            ->whereNotNull('schedule.booking_id');
        $created = (clone $base)->where('source.created_at', '>=', $coverageStart);
        $createdRows = (clone $created)->count();
        $createdMatched = (clone $created)->whereExists(function ($query) use ($coverageStart): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->whereColumn('history.booking_id', 'schedule.booking_id')
                ->whereColumn('history.entity_id', 'source.id')
                ->where('history.entity_type', 'VehicleMaintenanceRecord')
                ->where('history.occurred_at', '>=', $coverageStart)
                ->whereColumn('history.occurred_at', '>=', 'source.created_at')
                ->whereRaw($this->historyOperationMatchSql('created'));
            $this->requireHistoryItemBelongsToBooking($query);
        })->count();

        $mutations = (clone $base)->where('source.updated_at', '>=', $coverageStart)
            ->where(function ($query) use ($coverageStart): void {
                $query->whereColumn('source.updated_at', '>', 'source.created_at')
                    ->orWhere('source.deleted_at', '>=', $coverageStart);
            });
        $mutationRows = (clone $mutations)->count();
        $mutationMatched = (clone $mutations)->whereExists(function ($query) use ($coverageStart): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->whereColumn('history.booking_id', 'schedule.booking_id')
                ->whereColumn('history.entity_id', 'source.id')
                ->where('history.entity_type', 'VehicleMaintenanceRecord')
                ->where('history.occurred_at', '>=', $coverageStart)
                ->whereColumn('history.occurred_at', '>=', 'source.updated_at')
                ->whereRaw($this->historyOperationMatchSql(['updated', 'deleted', 'restored']));
            $this->requireHistoryItemBelongsToBooking($query);
        })->count();

        $sourceRows = $createdRows + $mutationRows;
        $matched = $createdMatched + $mutationMatched;
        return ['booking-linked maintenance records created/latest mutation states since coverage start', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function waitingTimeRecordParity(): ?array
    {
        $coverageStart = $this->verifiedCoverageStart;
        if (!$coverageStart || !Schema::hasTable('waiting_time_records')
            || !Schema::hasTable('driver_assignments')) return null;

        $base = DB::table('waiting_time_records as source')
            ->join('driver_assignments as assignment', 'assignment.id', '=', 'source.assignment_id')
            ->whereNotNull('assignment.booking_id');
        $created = (clone $base)->where('source.created_at', '>=', $coverageStart);
        $createdRows = (clone $created)->count();
        $createdMatched = (clone $created)->whereExists(function ($query) use ($coverageStart): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->whereColumn('history.booking_id', 'assignment.booking_id')
                ->where(function ($link): void {
                    $link->whereColumn('history.booking_item_id', 'assignment.booking_item_id')
                        ->orWhere(function ($unlinked): void {
                            $unlinked->whereNull('history.booking_item_id')->whereNull('assignment.booking_item_id');
                        });
                })
                ->whereColumn('history.entity_id', 'source.id')
                ->where('history.entity_type', 'WaitingTimeRecord')
                ->where('history.occurred_at', '>=', $coverageStart)
                ->whereColumn('history.occurred_at', '>=', 'source.created_at')
                ->whereRaw($this->historyOperationMatchSql('created'));
        })->count();

        $mutations = (clone $base)->where('source.updated_at', '>=', $coverageStart)
            ->where(function ($query) use ($coverageStart): void {
                $query->whereColumn('source.updated_at', '>', 'source.created_at')
                    ->orWhere('source.deleted_at', '>=', $coverageStart);
            });
        $mutationRows = (clone $mutations)->count();
        $mutationMatched = (clone $mutations)->whereExists(function ($query) use ($coverageStart): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->whereColumn('history.booking_id', 'assignment.booking_id')
                ->where(function ($link): void {
                    $link->whereColumn('history.booking_item_id', 'assignment.booking_item_id')
                        ->orWhere(function ($unlinked): void {
                            $unlinked->whereNull('history.booking_item_id')->whereNull('assignment.booking_item_id');
                        });
                })
                ->whereColumn('history.entity_id', 'source.id')
                ->where('history.entity_type', 'WaitingTimeRecord')
                ->where('history.occurred_at', '>=', $coverageStart)
                ->whereColumn('history.occurred_at', '>=', 'source.updated_at')
                ->whereRaw($this->historyOperationMatchSql(['updated', 'deleted', 'restored']));
        })->count();

        $sourceRows = $createdRows + $mutationRows;
        $matched = $createdMatched + $mutationMatched;
        return ['booking-linked waiting-time records created/latest mutation states since coverage start', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }
}
