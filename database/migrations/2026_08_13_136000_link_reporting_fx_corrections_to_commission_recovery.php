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
            $table->timestamp('fx_rate_at')->nullable()->after('fx_rate_to_lkr');
            $table->string('fx_source', 160)->nullable()->after('fx_rate_at');
            $table->string('fx_quote_base', 40)->nullable()->after('fx_source');
            $table->string('fx_calculation_mode', 40)->nullable()->after('fx_quote_base');
            $table->decimal('original_lkr_amount', 20, 4)->nullable()->after('fx_calculation_mode');
            $table->decimal('original_fx_rate_to_lkr', 20, 10)->nullable()->after('original_lkr_amount');
            $table->timestamp('original_fx_rate_at')->nullable()->after('original_fx_rate_to_lkr');
            $table->string('original_fx_source', 160)->nullable()->after('original_fx_rate_at');
            $table->decimal('lkr_delta', 20, 4)->nullable()->after('original_fx_source');
            $table->foreignUuid('commission_decision_id')->nullable()->after('lkr_delta')
                ->constrained('sales_commission_decisions')->restrictOnDelete();
        });

        Schema::table('sales_commission_recovery_cases', function (Blueprint $table) {
            $table->foreignUuid('beneficiary_sales_profile_id')->nullable()->after('beneficiary_staff_id')
                ->constrained('sales_profiles')->restrictOnDelete();
            $table->string('recovery_kind', 30)->default('cash_decrease')->after('beneficiary_sales_profile_id');
            $table->string('commission_category', 60)->nullable()->after('recovery_kind');
            $table->string('collection_cohort', 60)->nullable()->after('commission_category');
            $table->string('source_currency', 3)->nullable()->after('adjusted_source_amount');
            $table->decimal('original_eligible_lkr_amount', 20, 4)->nullable()->after('source_currency');
            $table->decimal('original_fx_rate_to_lkr', 20, 10)->nullable()->after('original_eligible_lkr_amount');
            $table->timestamp('original_fx_rate_at')->nullable()->after('original_fx_rate_to_lkr');
            $table->string('original_fx_source', 160)->nullable()->after('original_fx_rate_at');
            $table->decimal('corrected_lkr_amount', 20, 4)->nullable()->after('original_fx_source');
            $table->decimal('corrected_fx_rate_to_lkr', 20, 10)->nullable()->after('corrected_lkr_amount');
            $table->timestamp('corrected_fx_rate_at')->nullable()->after('corrected_fx_rate_to_lkr');
            $table->string('corrected_fx_source', 160)->nullable()->after('corrected_fx_rate_at');
            $table->string('corrected_fx_calculation_mode', 40)->nullable()->after('corrected_fx_source');
            $table->decimal('reporting_lkr_delta', 20, 4)->nullable()->after('corrected_fx_calculation_mode');
            $table->string('original_formula_kind', 40)->nullable()->after('reporting_lkr_delta');
            $table->decimal('original_applied_rate', 12, 6)->nullable()->after('original_formula_kind');
            $table->decimal('original_fixed_amount_lkr', 20, 4)->nullable()->after('original_applied_rate');
            $table->decimal('original_commission_amount_lkr', 20, 4)->nullable()->after('original_fixed_amount_lkr');
            $table->decimal('proposed_commission_adjustment_lkr', 20, 4)->nullable()->after('original_commission_amount_lkr');
            $table->string('calculation_status', 40)->default('ready')->after('proposed_commission_adjustment_lkr');
            $table->text('calculation_explanation')->nullable()->after('calculation_status');
            $table->index(['beneficiary_sales_profile_id', 'status', 'opened_at'], 'commission_recovery_profile_queue_idx');
        });

        DB::table('sales_commission_recovery_cases as recovery')
            ->join('sales_commission_decisions as earning', 'earning.id', '=', 'recovery.commission_decision_id')
            ->select([
                'recovery.id', 'earning.beneficiary_sales_profile_id', 'earning.commission_category',
                'earning.collection_cohort', 'earning.source_currency', 'earning.eligible_lkr_amount',
                'earning.fx_rate_to_lkr', 'earning.fx_rate_at', 'earning.fx_source', 'earning.formula_kind',
                'earning.applied_rate', 'earning.fixed_amount_lkr', 'earning.commission_amount_lkr',
                'recovery.proposed_recovery_lkr',
            ])->orderBy('recovery.id')->each(function ($row): void {
                DB::table('sales_commission_recovery_cases')->where('id', $row->id)->update([
                    'beneficiary_sales_profile_id' => $row->beneficiary_sales_profile_id,
                    'commission_category' => $row->commission_category, 'collection_cohort' => $row->collection_cohort,
                    'source_currency' => $row->source_currency, 'original_eligible_lkr_amount' => $row->eligible_lkr_amount,
                    'original_fx_rate_to_lkr' => $row->fx_rate_to_lkr, 'original_fx_rate_at' => $row->fx_rate_at,
                    'original_fx_source' => $row->fx_source, 'original_formula_kind' => $row->formula_kind,
                    'original_applied_rate' => $row->applied_rate, 'original_fixed_amount_lkr' => $row->fixed_amount_lkr,
                    'original_commission_amount_lkr' => $row->commission_amount_lkr,
                    'proposed_commission_adjustment_lkr' => -(float) $row->proposed_recovery_lkr,
                    'calculation_explanation' => 'Backfilled immutable original-earning evidence for an existing cash-decrease case.',
                ]);
            });

        Schema::table('sales_commission_recovery_decisions', function (Blueprint $table) {
            $table->char('request_payload_checksum', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        if (DB::table('booking_payment_adjustments')->where('impact_dimension', 'reporting_fx')->exists()
            || DB::table('sales_commission_recovery_cases')->where('recovery_kind', 'reporting_fx')->exists()) {
            throw new \RuntimeException('Rollback refused: export reporting-FX correction and linked commission evidence before removing its schema.');
        }

        Schema::table('sales_commission_recovery_cases', function (Blueprint $table) {
            $table->dropIndex('commission_recovery_profile_queue_idx');
            $table->dropConstrainedForeignId('beneficiary_sales_profile_id');
            $table->dropColumn([
                'recovery_kind', 'commission_category', 'collection_cohort', 'source_currency',
                'original_eligible_lkr_amount', 'original_fx_rate_to_lkr', 'original_fx_rate_at', 'original_fx_source',
                'corrected_lkr_amount', 'corrected_fx_rate_to_lkr', 'corrected_fx_rate_at', 'corrected_fx_source',
                'corrected_fx_calculation_mode',
                'reporting_lkr_delta', 'original_formula_kind', 'original_applied_rate', 'original_fixed_amount_lkr',
                'original_commission_amount_lkr', 'proposed_commission_adjustment_lkr', 'calculation_status',
                'calculation_explanation',
            ]);
        });
        Schema::table('booking_payment_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_decision_id');
            $table->dropColumn([
                'fx_rate_at', 'fx_source', 'fx_quote_base', 'fx_calculation_mode', 'original_lkr_amount', 'original_fx_rate_to_lkr',
                'original_fx_rate_at', 'original_fx_source', 'lkr_delta',
            ]);
        });
        Schema::table('sales_commission_recovery_decisions', function (Blueprint $table) {
            $table->dropColumn('request_payload_checksum');
        });
    }
};
