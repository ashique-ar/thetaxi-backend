<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $this->addChecksumCompatibilityColumns();
        $this->preserveRepairReplayEvidence();
        // These columns are Sales/payment tenant metadata; location search uses separate location selection data.
        $this->dropTenantCompany('booking_payment_receipts');
        $this->dropTenantCompany('sales_booking_attributions');
    }

    private function addChecksumCompatibilityColumns(): void
    {
        if (Schema::hasTable('booking_payment_receipts')) {
            Schema::table('booking_payment_receipts', function (Blueprint $table): void {
                if (! Schema::hasColumn('booking_payment_receipts', 'legacy_repair_checksum_version')) {
                    $table->unsignedSmallInteger('legacy_repair_checksum_version')->default(1);
                }
                if (! Schema::hasColumn('booking_payment_receipts', 'legacy_repair_after_checksum_v1')) {
                    $table->char('legacy_repair_after_checksum_v1', 64)->nullable();
                }
                if (! Schema::hasColumn('booking_payment_receipts', 'legacy_repair_state_checksum_v2')) {
                    $table->char('legacy_repair_state_checksum_v2', 64)->nullable();
                }
            });
        }

        if (Schema::hasTable('booking_payment_receipt_components')
            && ! Schema::hasColumn('booking_payment_receipt_components', 'repair_checksum_version')) {
            Schema::table('booking_payment_receipt_components', function (Blueprint $table): void {
                $table->unsignedSmallInteger('repair_checksum_version')->default(1);
            });
        }
    }

    private function preserveRepairReplayEvidence(): void
    {
        if (! Schema::hasTable('booking_payment_receipts')
            || ! Schema::hasColumn('booking_payment_receipts', 'legacy_repair_request_checksum')) {
            return;
        }

        $repairs = DB::table('booking_payment_receipts')
            ->whereNotNull('legacy_repair_request_checksum')
            ->where('legacy_repair_checksum_version', 1)
            ->whereNotNull('legacy_repair_before_checksum')->orderBy('id')->get();
        foreach ($repairs as $receipt) {
            $events = DB::table('financial_audit_events')->where('booking_id', $receipt->booking_id)
                ->where('subject_type', 'booking_payment')->where('subject_id', $receipt->id)
                ->where('event_type', 'legacy_payment_receipt_repaired')->limit(2)->get();
            if ($events->count() !== 1) {
                throw new RuntimeException("Cannot remove tenant company data: legacy payment repair {$receipt->id} has no unique audit event.");
            }

            $event = $events->first();
            $metadata = is_string($event->metadata) ? (json_decode($event->metadata, true) ?: []) : (array) $event->metadata;
            $companyId = Schema::hasColumn('sales_booking_attributions', 'company_id')
                ? (string) DB::table('sales_booking_attributions')->where('booking_id', $receipt->booking_id)->value('company_id')
                : '';
            $legacyAfter = (string) ($metadata['after_checksum'] ?? '');
            $legacyCurrent = $this->repairStateChecksum((string) $receipt->booking_id, $companyId);
            if (! $legacyAfter || ! hash_equals($legacyAfter, $legacyCurrent)
                || ! hash_equals((string) $receipt->legacy_repair_request_checksum, (string) ($metadata['request_checksum'] ?? ''))
                || ! hash_equals((string) $receipt->legacy_repair_before_checksum, (string) ($metadata['before_checksum'] ?? ''))
                || (string) $receipt->legacy_repair_evidence_file_id !== (string) ($metadata['evidence_file_id'] ?? '')
                || (string) $receipt->legacy_repair_reason !== (string) ($metadata['repair_reason'] ?? '')) {
                throw new RuntimeException("Cannot remove tenant company data: legacy payment repair {$receipt->id} no longer matches its immutable audit evidence; reconcile it first.");
            }

            DB::table('booking_payment_receipts')->where('id', $receipt->id)->update([
                'legacy_repair_checksum_version' => 1,
                'legacy_repair_after_checksum_v1' => $legacyAfter,
                'legacy_repair_state_checksum_v2' => $this->repairStateChecksum((string) $receipt->booking_id),
            ]);
        }
    }

    private function repairStateChecksum(string $bookingId, ?string $legacyCompanyId = null): string
    {
        $receiptIds = DB::table('booking_payment_receipts')->where('booking_id', $bookingId)->orderBy('id')->pluck('id');
        $scheduleIds = DB::table('booking_payment_schedules')->where('booking_id', $bookingId)->orderBy('id')->pluck('id');
        $receiptColumns = [
            'id', 'amount', 'refunded_amount', 'payment_method', 'payment_stage', 'payment_purpose', 'reference', 'idempotency_key',
            'received_at', 'received_by', 'notes', 'metadata', 'received_via', 'source_amount', 'source_currency',
            'lkr_amount', 'fx_rate_to_lkr', 'fx_rate_at', 'fx_source', 'finality_status', 'finalized_at',
            'allocated_amount', 'allocation_status', 'driver_company_settlement_status',
            'driver_company_settled_amount', 'driver_company_settled_at', 'event_version',
            'provider_event_id', 'provider_payload_checksum', 'request_payload_checksum',
            'legacy_repair_request_checksum', 'legacy_repair_before_checksum', 'legacy_repair_evidence_file_id', 'legacy_repair_reason',
        ];
        if ($legacyCompanyId !== null) {
            array_splice($receiptColumns, 13, 0, ['company_id']);
        }

        $state = [];
        if ($legacyCompanyId !== null) {
            $state['company_id'] = $legacyCompanyId;
        }
        $state += [
            'booking' => DB::table('bookings')->where('id', $bookingId)->first([
                'id', 'total_actual', 'total_estimated', 'amount_to_pay', 'payment_status',
                'payment_collection_status', 'payment_collected_amount', 'payment_collected_at',
                'payment_method', 'payment_reference', 'payment_notes', 'payment_arrangement_status',
                'customer_settlement_status', 'corporate_settlement_status', 'driver_collection_status', 'settled_at',
                'invoice_status',
            ]),
            'receipts' => DB::table('booking_payment_receipts')->where('booking_id', $bookingId)->orderBy('id')->get($receiptColumns),
            'components' => DB::table('booking_payment_receipt_components')->whereIn('receipt_id', $receiptIds)->orderBy('id')->get([
                'id', 'receipt_id', 'component_type', 'source_amount', 'lkr_amount', 'is_allocatable',
                'is_collection_target_eligible', 'is_commission_eligible', 'allocated_source_amount', 'adjusted_source_amount',
                'repair_idempotency_key', 'repair_request_checksum', 'repair_before_checksum',
                'repair_evidence_file_id', 'repair_reason',
            ]),
            'schedules' => DB::table('booking_payment_schedules')->where('booking_id', $bookingId)->orderBy('id')->get(['id', 'amount', 'status']),
            'allocations' => DB::table('booking_payment_schedule_allocations as allocation')
                ->join('booking_payment_schedules as schedule', 'schedule.id', '=', 'allocation.booking_payment_schedule_id')
                ->where('schedule.booking_id', $bookingId)->orderBy('allocation.id')
                ->get(['allocation.id', 'allocation.booking_payment_schedule_id', 'allocation.booking_payment_receipt_id', 'allocation.amount', 'allocation.allocated_at']),
            'adjustments' => DB::table('booking_payment_adjustments')->where('booking_id', $bookingId)->orderBy('id')->get([
                'id', 'receipt_id', 'receipt_component_id', 'impact_dimension', 'adjustment_type', 'direction',
                'source_amount', 'source_currency', 'lkr_amount', 'adjustment_effective_at', 'idempotency_key',
                'request_payload_checksum',
            ]),
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function dropTenantCompany(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }
        foreach (Schema::getIndexes($tableName) as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                TenantIndexDropper::drop($tableName, $index);
            }
        }
        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        throw new RuntimeException('Sales payment records cannot regain tenant company scope.');
    }
};
