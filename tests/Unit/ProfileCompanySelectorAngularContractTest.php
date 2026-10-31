<?php

it('keeps Sales Profile administration installation-wide without a company selector', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-profile-administration/sales-profile-administration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-profile-administration/sales-profile-administration.component.html'));

    expect($routes)->not->toContain("profile-company-options")
        ->and($controller)->toContain("'sales_staff_categories' => $this->salesStaffCategories()", "'profile_export_ready' =>")
        ->toContain("whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())")
        ->and($component)->toContain('revision !== this.contextRevision', 'loadScoped(true)')->not->toContain('companyId', 'company_id', 'profileCompanyOptions()', 'preselectedCompany(response)')
        ->not->toContain('companies = signal', 'response.data?.companies')
        ->and($template)->not->toContain('companyId', 'profile-company-options', 'Select an administered legal entity')
        ->not->toContain('*ngFor="let row of companies()"');
});
