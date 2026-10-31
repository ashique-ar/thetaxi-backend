<?php

it('uses authorized Sales Profile scopes for dashboard views without a company resolver', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain('private function dashboardProfileIds(', '$this->scope->profileIds(', 'public function show(')
        ->not->toContain('resolveCompanyId(', 'activeDefaultCompany', 'company_id')
        ->and($routes)
        ->toContain("Route::prefix('sales-performance')", "Route::get('management', [SalesDashboardController::class, 'show'])")
        ->toContain("Route::get('me', [SalesDashboardController::class, 'show'])")
        ->toContain("Route::get('staff/{staffId}', [SalesDashboardController::class, 'staff'])");
});
