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
            $table->foreignUuid('corrects_adjustment_id')->nullable()->after('commission_decision_id')
                ->constrained('booking_payment_adjustments')->restrictOnDelete();
            $table->foreignUuid('lineage_root_adjustment_id')->nullable()->after('corrects_adjustment_id')
                ->constrained('booking_payment_adjustments')->restrictOnDelete();
            $table->unsignedInteger('correction_sequence')->nullable()->after('lineage_root_adjustment_id');
            $table->decimal('prior_corrected_lkr_amount', 20, 4)->nullable()->after('correction_sequence');
            $table->decimal('prior_fx_rate_to_lkr', 20, 10)->nullable()->after('prior_corrected_lkr_amount');
            $table->timestamp('prior_fx_rate_at')->nullable()->after('prior_fx_rate_to_lkr');
            $table->string('prior_fx_source', 160)->nullable()->after('prior_fx_rate_at');
            $table->decimal('cumulative_lkr_delta', 20, 4)->nullable()->after('lkr_delta');
            $table->unique('corrects_adjustment_id', 'booking_adjustment_single_successor_unique');
            $table->unique(['receipt_component_id', 'correction_sequence'], 'booking_adjustment_component_sequence_unique');
            $table->index(['lineage_root_adjustment_id', 'correction_sequence'], 'booking_adjustment_fx_lineage_idx');
        });

        DB::table('booking_payment_adjustments')->where('impact_dimension', 'reporting_fx')->update([
            'correction_sequence' => 1,
            'cumulative_lkr_delta' => DB::raw('lkr_delta'),
        ]);

        Schema::table('sales_commission_recovery_cases', function (Blueprint $table) {
            $table->foreignUuid('corrects_recovery_case_id')->nullable()->after('payment_adjustment_id')
                ->constrained('sales_commission_recovery_cases')->restrictOnDelete();
            $table->decimal('prior_recalculated_commission_amount_lkr', 20, 4)->nullable()
                ->after('original_commission_amount_lkr');
            $table->unique('corrects_recovery_case_id', 'commission_recovery_single_successor_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('booking_payment_adjustments')->whereNotNull('corrects_adjustment_id')->exists()) {
            throw new \RuntimeException('Rollback refused: export and reconcile reporting-FX counter-adjustment lineages first.');
        }

        Schema::table('sales_commission_recovery_cases', function (Blueprint $table) {
            $table->dropUnique('commission_recovery_single_successor_unique');
            $table->dropConstrainedForeignId('corrects_recovery_case_id');
            $table->dropColumn('prior_recalculated_commission_amount_lkr');
        });
        Schema::table('booking_payment_adjustments', function (Blueprint $table) {
            $table->dropUnique('booking_adjustment_single_successor_unique');
            $table->dropUnique('booking_adjustment_component_sequence_unique');
            $table->dropIndex('booking_adjustment_fx_lineage_idx');
            $table->dropConstrainedForeignId('lineage_root_adjustment_id');
            $table->dropConstrainedForeignId('corrects_adjustment_id');
            $table->dropColumn([
                'correction_sequence', 'prior_corrected_lkr_amount', 'prior_fx_rate_to_lkr',
                'prior_fx_rate_at', 'prior_fx_source', 'cumulative_lkr_delta',
            ]);
        });
    }
};
