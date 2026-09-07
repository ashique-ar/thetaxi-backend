<?php

it('uses a bounded active-Staff-scoped company selector for commission configuration', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionConfigurationController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-configuration/sales-commission-configuration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-configuration/sales-commission-configuration.component.html'));

    expect($routes)->toContain("Route::get('commission-configuration/company-options', [CommissionConfigurationController::class, 'companyOptions'])")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())")
        ->toContain("where('id', \$companyId)->whereNull('deleted_at')->exists()")
        ->and($component)->toContain('UiManagedRecordSelectComponent', 'resetTenantState()', 'revision !== this.loadRevision')->not->toContain('commissionConfigurationContext()', 'companies = signal')
        ->and($template)->toContain('endpoint="/sales/commission-configuration/company-options"', 'title="Select a legal entity"')
        ->not->toContain('*ngFor="let company of companies()"');
});
