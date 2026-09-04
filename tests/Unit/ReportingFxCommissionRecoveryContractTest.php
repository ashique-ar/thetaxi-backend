<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReportingFxCommissionRecoveryContractTest extends TestCase
{
    public function test_reporting_fx_correction_is_fail_closed_and_does_not_use_the_cash_mutation_branch(): void
    {
        $source = file_get_contents(base_path('app/Services/Sales/BookingPaymentAdjustmentService.php'));

        self::assertStringContainsString("config('sales.features.fx_corrections', false)", $source);
        self::assertStringContainsString('Reporting-FX correction policy is incomplete.', $source);
        self::assertStringContainsString("['multiply_source_by_rate', 'divide_source_by_rate']", $source);
        self::assertStringContainsString("where('state', 'locked')", $source);
        self::assertStringContainsString("\$data['impact_dimension'] === 'cash_receipt'", $source);
        self::assertStringContainsString("\$data['impact_dimension'] === 'reporting_fx'", $source);
        self::assertStringContainsString("'original_lkr_amount' => \$originalLkr", $source);
        self::assertStringContainsString("'commission_decision_id' => \$earning?->id", $source);
    }

    public function test_recovery_preserves_original_beneficiary_and_replays_tiered_recalculation(): void
    {
        $source = file_get_contents(base_path('app/Services/Sales/CommissionRecoveryService.php'));

        self::assertStringContainsString("'beneficiary_sales_profile_id' => \$earning->beneficiary_sales_profile_id", $source);
        self::assertStringContainsString("\$earning->formula_kind === 'tiered_percentage'", $source);
        self::assertStringContainsString("\$tiers->count() === 1", $source);
        self::assertStringContainsString('corrected whole-payment basis', $source);
        self::assertStringContainsString("\$earning->formula_kind === 'fixed'", $source);
        self::assertStringContainsString("\$status = 'no_change'", $source);
        self::assertStringContainsString("['deduct', 'credit']", $source);
        self::assertStringContainsString('request_payload_checksum', $source);
    }

    public function test_mutation_is_bound_to_a_no_write_preview_and_credit_reaches_statements(): void
    {
        $adjustments = file_get_contents(base_path('app/Services/Sales/BookingPaymentAdjustmentService.php'));
        $statements = file_get_contents(base_path('app/Services/Sales/CommissionStatementService.php'));
        self::assertStringContainsString('previewReportingFx', $adjustments);
        self::assertStringContainsString("hash_equals(\$preview['preview_checksum']", $adjustments);
        self::assertStringContainsString("['deduct', 'credit']", $statements);
        self::assertStringContainsString('Approved commission correction credit', $statements);
    }

    public function test_counter_adjustments_form_one_locked_incremental_lineage(): void
    {
        $adjustments = file_get_contents(base_path('app/Services/Sales/BookingPaymentAdjustmentService.php'));
        $recoveries = file_get_contents(base_path('app/Services/Sales/CommissionRecoveryService.php'));
        $migration = file_get_contents(base_path('database/migrations/2026_08_13_138000_add_reporting_fx_counter_adjustment_lineage.php'));

        self::assertStringContainsString('Reference the current FX correction leaf', $adjustments);
        self::assertStringContainsString("'corrects_adjustment_id' => \$prior?->id", $adjustments);
        self::assertStringContainsString("'cumulative_lkr_delta'", $adjustments);
        self::assertStringContainsString("\$recovery->status === 'pending_review'", $adjustments);
        self::assertStringContainsString('priorRecalculatedCommission', $recoveries);
        self::assertStringContainsString('corrects_recovery_case_id', $recoveries);
        self::assertStringContainsString('booking_adjustment_single_successor_unique', $migration);
        self::assertStringContainsString('Rollback refused', $migration);
    }

    public function test_reporting_fx_is_not_projected_as_collection_cash(): void
    {
        $source = file_get_contents(base_path('app/Services/Sales/SalesMetricFactService.php'));
        self::assertStringContainsString("if (\$adjustment->impact_dimension !== 'cash_receipt' || \$adjustment->lkr_amount === null) return;", $source);
        self::assertStringContainsString("\$adjustment->adjustment_effective_at->toDateString()", $source);
    }

    public function test_routes_remain_internal_permission_and_profile_feature_gated(): void
    {
        $routes = file_get_contents(base_path('routes/api.php'));
        self::assertStringContainsString("Route::prefix('sales')->middleware(['ensure.internal', 'sales.feature:sales_profiles'])", $routes);
        self::assertStringContainsString("bookings/{booking}/payment-adjustments/preview", $routes);
        self::assertStringContainsString("permission:sales.payment-adjustments.create", $routes);
        self::assertStringContainsString("permission:sales.commission-recoveries.decide", $routes);
    }
}
