<?php

it('preselects an authorized default for the bounded Performance company selector', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/services/sales.service.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance/sales-performance.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance/sales-performance.component.html'));

    expect($routes)->not->toContain("Route::get('dashboard-context'")
        ->and($service)->toContain('performanceAdministrationContext()')
        ->and($controller)->toContain("where('id', \$companyId)->whereNull('deleted_at')->exists()")
        ->and($component)->toContain('UiManagedRecordSelectComponent', 'requestVersion !== this.dashboardRequestVersion', 'resetDashboardState()', 'companyContextRequestVersion', 'companyContextRequestVersion++', 'default_company_id', 'this.companyChanged()')
        ->not->toContain('companies = signal')
        ->and($template)->toContain('endpoint="/sales/performance/company-options"', 'title="Select a legal entity"')
        ->not->toContain('*ngFor="let company of companies()"');
});
