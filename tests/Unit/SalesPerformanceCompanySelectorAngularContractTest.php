<?php

it('uses bounded scoped company selectors throughout performance administration without UUID label fallbacks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance-administration/sales-performance-administration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance-administration/sales-performance-administration.component.html'));

    expect($routes)->toContain("Route::get('performance/company-options', [SalesPerformanceController::class, 'companyOptions'])")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("\$this->scope->profileIds(")
        ->toContain("where('status', 'active')->activeAt(now())")
        ->toContain("whereNull('deleted_at')->whereIn('id', \$companyIds)")
        ->and(substr_count($template, 'endpoint="/sales/performance/company-options"'))->toBe(6)
        ->and($template)->not->toContain('name="targetFilterCompany"\n            (selectionChange)', '*ngFor="let row of companies()" [value]="row.id"')
        ->and($component)->toContain('UiManagedRecordSelectComponent', "'Unavailable legal entity'", "'Unavailable Sales Profile'")
        ->toContain('contextRequestVersion', 'companyLabels')
        ->not->toContain('?.name || id', ': id; }', 'companies()[0]', 'data?.companies');
});
