<?php

it('keeps inquiry assignment on the single default company and uses readable Staff choices', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/InquiryController.php'));
    $request = file_get_contents(app_path('Http/Requests/Inquiry/CreateInquiryRequest.php'));
    $resource = file_get_contents(app_path('Http/Resources/Inquiry/InquiryResource.php'));
    $scope = file_get_contents(app_path('Services/SingleCompanyScope.php'));
    $page = file_get_contents(base_path('../portal-thetaxi/src/app/modules/communication/inquiry-detail/inquiry-detail.component.html'));

    expect($controller)->toContain("'assigneeOptions'", 'activeDefaultCompany(true)', "->lockForUpdate()->firstOrFail()", "->lockForUpdate()->first(['users.id'])")
        ->toContain("where('staff.company_id', \$companyId)", "where('users.is_active', true)", "whereNull('hr_employment_spells.terminated_at')")
        ->and($request)->toContain("'assigned_to' => ['prohibited']")
        ->and($scope)->toContain("where('is_active', true)", "where('is_default', true)", 'limit(2)', 'lockForUpdate()')
        ->and($resource)->toContain("'assigned_label'", 'activeDefaultCompany()')
        ->and($page)->toContain('endpoint="/inquiries/assignee-options"')
        ->not->toContain('User UUID');
});
