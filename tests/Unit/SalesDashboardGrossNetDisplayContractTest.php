<?php

it('exposes gross, adjustment, and net New Sales additively without altering the existing gross-keyed fields', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($service)
        ->toContain("\$newSalesAdjustment = \$metric('new_sales_adjustment', 'new_business');")
        ->toContain('\'gross_new_sales_lkr\' => $newSales,')
        ->toContain('\'new_sales_adjustment_lkr\' => $newSalesAdjustment,')
        ->toContain('\'net_new_sales_lkr\' => $netNewSales,')
        ->toContain("'new_sales_achievement_percent' => \$newTarget === null || \$newTarget <= 0 ? null : round(\$newSales / \$newTarget * 100, 4),");
});

it('sums the new gross/adjustment/net fields into the dashboard summary without removing the existing gross field', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));

    expect($controller)->toContain("\$summaryFields = ['new_sales_lkr', 'gross_new_sales_lkr', 'new_sales_adjustment_lkr', 'net_new_sales_lkr',");
});

it('never widens SalesKpiSnapshotRow fillable to accept the new dashboard-only fields, keeping the frozen snapshot schema unchanged', function () {
    $model = file_get_contents(app_path('Models/Sales/SalesKpiSnapshotRow.php'));

    expect($model)
        ->not->toContain('gross_new_sales_lkr')
        ->not->toContain('new_sales_adjustment_lkr')
        ->not->toContain('net_new_sales_lkr');
});
