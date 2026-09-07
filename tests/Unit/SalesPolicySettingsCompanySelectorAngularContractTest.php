<?php

it('uses a bounded active-Staff-scoped company selector for governed Sales settings', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPolicySettingsController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-policy-settings/sales-policy-settings.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-policy-settings/sales-policy-settings.component.html'));

    expect($routes)->toContain("Route::get('policy-settings/company-options', [SalesPolicySettingsController::class, 'companyOptions'])")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())")
        ->toContain("where('id', \$companyId)->whereNull('deleted_at')->exists()")
        ->and($component)->toContain('UiManagedRecordSelectComponent')->not->toContain('policySettingsContext()', 'companies = signal')
        ->and($template)->toContain('endpoint="/sales/policy-settings/company-options"', 'title="Select a legal entity"')
        ->not->toContain('*ngFor="let company of companies()"');
});
