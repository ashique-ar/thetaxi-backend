<?php

it('governs active portfolio status without inventing booking statuses', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_31_150000_create_sales_portfolio_status_policy_versions.php'));
    $service = file_get_contents(app_path('Services/Sales/SalesPortfolioStatusService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($migration)
        ->toContain("Schema::create('sales_portfolio_status_policy_versions'")
        ->toContain("unique(['company_id', 'idempotency_key']")
        ->toContain('Refusing to drop retained Sales portfolio status-policy evidence')
        ->and($service)
        ->toContain('active_booking_statuses')
        ->toContain('CONFIGURATION_MISSING: no approved active-booking status policy')
        ->toContain('Portfolio status policy maker and approver must be different users.')
        ->toContain("'sales.portfolio_status_policy.drafted'")
        ->toContain("'sales.portfolio_status_policy.approved'")
        ->and($controller)->toContain('createPortfolioStatusPolicy')->toContain('approvePortfolioStatusPolicy')
        ->and($routes)->toContain("Route::get('performance/portfolio-status-policies'")
        ->toContain('sales.performance.portfolio-status-policies.manage')
        ->toContain('sales.performance.portfolio-status-policies.approve');
});

it('keeps active portfolio current scoped paginated and open ended value safe', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPortfolioStatusService.php'));
    $dashboard = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance/sales-performance.component.html'));

    expect($service)
        ->toContain("'historical_reconstruction_required'")
        ->toContain("->whereNull('attribution.root_attribution_id')")
        ->toContain("->whereIn('booking.status', \$statuses)")
        ->toContain("->whereIn('attribution.acquisition_sales_profile_id', \$profileIds)")
        ->toContain("->orWhereIn('attribution.collection_sales_profile_id', \$profileIds)")
        ->toContain("'not_applicable_open_ended'")
        ->toContain("'open_ended_monthly_run_rate_lkr'")
        ->toContain("'open_ended_generated_horizon_lkr'")
        ->toContain("'open_ended_collected_lkr'")
        ->toContain("'open_ended_generated_outstanding_lkr'")
        ->toContain('unset($row->rolling_rule_id, $row->acquisition_sales_profile_id, $row->collection_sales_profile_id)')
        ->and($dashboard)->toContain('public function activePortfolio(')->toContain('dashboardProfileIds(')
        ->and($template)->toContain('Governed active portfolio')
        ->toContain('Open-ended monthly run rate')
        ->toContain('*hasPermission="\'bookings.view\'"');
});
