<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payment_receipt_finality_events', function (Blueprint $table) {
            $table->foreignUuid('finality_policy_id')->nullable()->after('booking_payment_receipt_id')
                ->constrained('booking_payment_finality_policies')->restrictOnDelete();
            $table->index('finality_policy_id', 'receipt_finality_event_policy_idx');
        });

        Schema::table('sales_commission_hold_adjustments', function (Blueprint $table) {
            $table->foreignUuid('beneficiary_attribution_event_id')->nullable()->after('source_attribution_event_id')
                ->constrained('sales_booking_attribution_events')->restrictOnDelete();
            $table->foreignUuid('finality_policy_id')->nullable()->after('source_attribution_event_id')
                ->constrained('booking_payment_finality_policies')->restrictOnDelete();
            $table->foreignUuid('receipt_finality_event_id')->nullable()->after('finality_policy_id')
                ->constrained('booking_payment_receipt_finality_events')->restrictOnDelete();
            $table->index('receipt_finality_event_id', 'commission_hold_adjustment_finality_event_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_commission_hold_adjustments DROP CONSTRAINT IF EXISTS sales_commission_hold_adjustment_kind');
            DB::statement("ALTER TABLE sales_commission_hold_adjustments ADD CONSTRAINT sales_commission_hold_adjustment_kind CHECK ((adjustment_kind = 'late_attribution_entitlement' AND original_hold_code = 'attribution_missing' AND receipt_finality_event_id IS NULL) OR (adjustment_kind = 'late_acquisition_owner_entitlement' AND original_hold_code IN ('acquisition_profile_missing', 'acquisition_profile_ineligible', 'legal_entity_mismatch') AND beneficiary_attribution_event_id IS NOT NULL AND finality_policy_id IS NOT NULL AND receipt_finality_event_id IS NULL) OR (adjustment_kind = 'late_finality_policy_entitlement' AND original_hold_code IN ('finality_policy_missing', 'finality_policy_invalid') AND finality_policy_id IS NOT NULL AND receipt_finality_event_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::table('sales_commission_hold_adjustments')
            ->whereIn('adjustment_kind', [
                'late_acquisition_owner_entitlement', 'late_finality_policy_entitlement',
            ])->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile late acquisition/finality commission entitlement evidence first.');
        }
        if (DB::table('booking_payment_receipt_finality_events')->whereNotNull('finality_policy_id')->exists()) {
            throw new RuntimeException('Rollback refused: receipt-finality events already freeze approved policy evidence.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_commission_hold_adjustments DROP CONSTRAINT IF EXISTS sales_commission_hold_adjustment_kind');
            DB::statement("ALTER TABLE sales_commission_hold_adjustments ADD CONSTRAINT sales_commission_hold_adjustment_kind CHECK (adjustment_kind = 'late_attribution_entitlement' AND original_hold_code = 'attribution_missing')");
        }
        Schema::table('sales_commission_hold_adjustments', function (Blueprint $table) {
            $table->dropIndex('commission_hold_adjustment_finality_event_idx');
            $table->dropConstrainedForeignId('receipt_finality_event_id');
            $table->dropConstrainedForeignId('finality_policy_id');
            $table->dropConstrainedForeignId('beneficiary_attribution_event_id');
        });
        Schema::table('booking_payment_receipt_finality_events', function (Blueprint $table) {
            $table->dropIndex('receipt_finality_event_policy_idx');
            $table->dropConstrainedForeignId('finality_policy_id');
        });
    }
};
