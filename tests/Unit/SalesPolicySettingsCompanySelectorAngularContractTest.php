<?php

it('keeps governed Sales settings installation-wide in routes and portal', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPolicySettingsController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-policy-settings/sales-policy-settings.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-policy-settings/sales-policy-settings.component.html'));

    expect($routes)->not->toContain('policy-settings/company-options')
        ->and($controller)->not->toContain('company_id', 'companyOptions', 'default_company_id')
        ->and($component)->toContain('feature_settings')->not->toContain('policySettingsCompanyOptions', 'companyId', 'companies = signal')
        ->and($template)->not->toContain('company-options', 'Select a legal entity');
});