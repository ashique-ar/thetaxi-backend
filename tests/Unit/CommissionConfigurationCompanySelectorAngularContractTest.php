<?php

it('keeps commission configuration installation-wide in its API and portal', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionConfigurationController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-configuration/sales-commission-configuration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-configuration/sales-commission-configuration.component.html'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/services/sales.service.ts'));

    expect($routes)->not->toContain('commission-configuration/company-options')
        ->and($controller)->not->toContain('company_id', 'CompanyIdScope', 'resolveCompanyId', 'assertCompanyScope')
        ->and($component)->toContain('this.api.commissionConfiguration()')
        ->not->toContain('companyId', 'companies', 'companyContext', 'legal-entity')
        ->and($template)->toContain('Commission rules apply across this installation', 'installation-wide')
        ->not->toContain('company-wide', 'Select a legal entity', 'company-options')
        ->and($service)->toContain('commissionConfiguration(): Observable<any>')
        ->not->toContain('commissionConfigurationCompanyOptions');

    expect($controller)->toContain("Rule::in(['installation', 'staff_category', 'sales_profile', 'employee'])")
        ->not->toContain("'company' =>", "where('company_id'");
});
