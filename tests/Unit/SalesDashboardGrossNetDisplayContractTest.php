<?php

it('uses complete net New Sales for target achievement variance and ranking', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($service)
        ->toContain("'new_sales_lkr' => \$newSalesValueComplete ? \$netNewSales : null")
        ->toContain("'new_sales_achievement_percent' => ! \$newSalesValueComplete")
        ->toContain('round($netNewSales / $newTarget * 100, 4)')
        ->toContain('round($netNewSales - $newTarget, 4)')
        ->toContain("'keys' => ['new_sales_achievement_percent', 'collection_achievement_percent', 'net_new_sales_lkr', 'eligible_collections_lkr']");
});

it('fails closed when a retained commercial adjustment lacks governed LKR evidence', function () {
    $adjustments = file_get_contents(app_path('Services/Sales/BookingCommercialValueAdjustmentService.php'));
    $metricFacts = file_get_contents(app_path('Services/Sales/SalesMetricFactService.php'));
    $performance = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $close = file_get_contents(app_path('Services/Sales/SalesPeriodCloseService.php'));

    expect($adjustments)->toContain('A governed LKR value is required before a commercial adjustment can affect New Sales.')
        ->and($metricFacts)->toContain('abort_if($adjustment->delta_lkr_amount === null')
        ->not->toContain('(float) ($adjustment->delta_lkr_amount ?? 0)')
        ->and($performance)->toContain("->whereNull('adjustment.delta_lkr_amount')")
        ->toContain("'new_sales_value_state' => \$newSalesValueComplete ? 'complete' : 'incomplete'")
        ->and($close)->toContain("'code' => 'net_new_sales_lkr_incomplete'");
});

it('freezes gross adjustment and net values without rewriting legacy snapshot rows', function () {
    $model = file_get_contents(app_path('Models/Sales/SalesKpiSnapshotRow.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_15_103000_govern_net_new_sales_kpi_snapshots.php'));

    expect($model)->toContain("'gross_new_sales_lkr', 'new_sales_adjustment_lkr'")
        ->toContain("'net_new_sales_lkr', 'new_sales_value_state'")
        ->and($migration)->toContain("->decimal('gross_new_sales_lkr', 20, 4)->nullable()")
        ->toContain("->string('new_sales_value_state', 30)->nullable()")
        ->toContain('Net New Sales snapshot evidence exists');
});

it('reconciles net source facts and makes legacy frozen trend rows incomplete', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $close = file_get_contents(app_path('Services/Sales/SalesPeriodCloseService.php'));

    expect($controller)->toContain("'new_sales' => ['new_sales', 'new_sales_adjustment']")
        ->toContain('COUNT(row.net_new_sales_lkr) net_new_sales_row_count')
        ->toContain("data_get(\$metrics, 'new_sales_value_state') === 'complete'")
        ->and($close)->toContain("'new_sales_adjustment_lkr' => [(float) (\$factMap->get('new_sales_adjustment')['amount_lkr'] ?? 0)")
        ->toContain("'net_new_sales_lkr' => [");
});

it('keeps no-sale quantity factual while decline and reliance consume governed net evidence', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($service)->toContain("(int) \$row->new_bookings_count === 0")
        ->toContain("->where('snapshot.period_type', 'month')")
        ->toContain('$historyPeriodsComplete')
        ->toContain("->where('row.new_sales_value_state', 'complete')->whereNotNull('row.net_new_sales_lkr')")
        ->toContain("\$history->avg('net_new_sales_lkr')")
        ->toContain("(\$metrics['new_sales_value_state'] ?? 'incomplete') !== 'complete'");
});
