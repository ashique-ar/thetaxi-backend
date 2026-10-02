<?php

it('uses the bounded Performance company selector without a dashboard preload', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/services/sales.service.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance/sales-performance.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance/sales-performance.component.html'));

    expect($routes)->not->toContain("Route::get('dashboard-context'")
        ->and($service)->not->toContain('dashboardContext()')
        ->and($controller)->toContain("where('id', \$companyId)->whereNull('deleted_at')->exists()")
        ->and($component)->toContain('UiManagedRecordSelectComponent', 'requestVersion !== this.dashboardRequestVersion', 'resetDashboardState()')
        ->not->toContain('companies = signal')
        ->and($template)->toContain('endpoint="/sales/performance/company-options"', 'title="Select a legal entity"')
        ->not->toContain('*ngFor="let company of companies()"');
});
