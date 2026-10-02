<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payment_adjustments', function (Blueprint $table) {
            $table->char('preview_checksum', 64)->nullable()->after('request_payload_checksum');
        });
        Schema::table('sales_commission_recovery_cases', function (Blueprint $table) {
            $table->decimal('corrected_eligible_lkr_amount', 20, 4)->nullable()->after('reporting_lkr_delta');
            $table->foreignUuid('recalculated_plan_tier_id')->nullable()->after('corrected_eligible_lkr_amount')
                ->constrained('sales_commission_plan_tiers')->restrictOnDelete();
            $table->unsignedInteger('recalculated_tier_sequence')->nullable()->after('recalculated_plan_tier_id');
            $table->decimal('recalculated_tier_minimum_lkr', 20, 4)->nullable()->after('recalculated_tier_sequence');
            $table->decimal('recalculated_tier_maximum_lkr', 20, 4)->nullable()->after('recalculated_tier_minimum_lkr');
            $table->boolean('recalculated_tier_minimum_inclusive')->nullable()->after('recalculated_tier_maximum_lkr');
            $table->boolean('recalculated_tier_maximum_inclusive')->nullable()->after('recalculated_tier_minimum_inclusive');
            $table->decimal('recalculated_rate', 12, 6)->nullable()->after('recalculated_tier_maximum_inclusive');
            $table->decimal('recalculated_commission_amount_lkr', 20, 4)->nullable()->after('recalculated_rate');
            $table->char('calculation_checksum', 64)->nullable()->after('calculation_explanation');
        });
    }

    public function down(): void
    {
        if (DB::table('booking_payment_adjustments')->whereNotNull('preview_checksum')->exists()
            || DB::table('sales_commission_recovery_cases')->whereNotNull('calculation_checksum')->exists()) {
            throw new \RuntimeException('Rollback refused: export and reconcile preview-bound FX commission recalculations first.');
        }
        Schema::table('sales_commission_recovery_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recalculated_plan_tier_id');
            $table->dropColumn([
                'corrected_eligible_lkr_amount', 'recalculated_tier_sequence', 'recalculated_tier_minimum_lkr',
                'recalculated_tier_maximum_lkr', 'recalculated_tier_minimum_inclusive',
                'recalculated_tier_maximum_inclusive', 'recalculated_rate', 'recalculated_commission_amount_lkr',
                'calculation_checksum',
            ]);
        });
        Schema::table('booking_payment_adjustments', function (Blueprint $table) {
            $table->dropColumn('preview_checksum');
        });
    }
};
