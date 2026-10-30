<?php

it('keeps Staff context creation independent from location selection', function () {
    $contextService = file_get_contents(app_path('Services/UserContextService.php'));
    $staffController = file_get_contents(app_path('Http/Controllers/Api/StaffController.php'));
    $staffForm = file_get_contents(base_path('../portal-thetaxi/src/app/modules/staff/components/staff-form/staff-form.component.ts'));

    expect($contextService)
        ->not->toContain('StaffDefaultCompanyService', 'defaultStaffCompany', 'companies')
        ->and($staffController)->not->toContain('defaultCompany', 'default_company_id', 'company_id')
        ->and($staffForm)->not->toContain('legal-entity-select', 'company_id');
});

it('keeps the companies API and editor focused on locations without a default-company marker', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/CompanyController.php'));
    $model = file_get_contents(app_path('Models/Company.php'));
    $companyForm = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/companies/form/company-form.component.html'));

    expect($controller)->toContain('Location options loaded')->not->toContain('default_company_id', 'is_default')
        ->and($model)->not->toContain("'is_default'")
        ->and($companyForm)->not->toContain('formControlName="is_default"', 'Default company');
});
