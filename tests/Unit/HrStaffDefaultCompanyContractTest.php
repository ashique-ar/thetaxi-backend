<?php

it('bootstraps Head Office as the single default and backfills only unassigned legacy Staff', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_31_172000_govern_default_staff_company.php'));

    expect($migration)
        ->toContain('Casons Rent A Car - Head Office')
        ->toContain("whereNull('company_id')")
        ->toContain("update(['company_id' => \$defaultId")
        ->toContain('companies_one_active_default_unique');
});

it('applies the dynamic company default server-side while preserving an explicit Staff override', function () {
    $service = file_get_contents(app_path('Services/Hr/StaffDefaultCompanyService.php'));
    $staffController = file_get_contents(app_path('Http/Controllers/Api/StaffController.php'));
    $contextService = file_get_contents(app_path('Services/UserContextService.php'));

    expect($service)
        ->toContain("where('is_default', true)")
        ->toContain("empty(\$staffData['company_id'])")
        ->and($staffController)
        ->toContain('$this->defaultCompany->apply($request->validated())')
        ->and($contextService)
        ->toContain('$this->defaultStaffCompany->apply($contextData)');
});

it('lets authorized admins choose one active default and exposes it in Company and Staff forms', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/CompanyController.php'));
    $companyForm = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/companies/form/company-form.component.html'));
    $staffForm = file_get_contents(base_path('../portal-thetaxi/src/app/modules/staff/components/staff-form/staff-form.component.ts'));
    $legalEntitySelect = file_get_contents(base_path('../portal-thetaxi/src/app/shared/components/ui/legal-entity-select/legal-entity-select.component.ts'));

    expect($controller)
        ->toContain("update(['is_default' => false])")
        ->toContain('The default Staff company must remain active.')
        ->toContain('Select another active company as default before deleting this company.')
        ->and($companyForm)->toContain('formControlName="is_default"')
        ->and($staffForm)->toContain('app-ui-legal-entity-select')
        ->and($legalEntitySelect)->toContain("find(company => company.is_default)?.id");
});
