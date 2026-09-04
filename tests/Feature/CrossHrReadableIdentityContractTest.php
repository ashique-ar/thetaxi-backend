<?php

it('keeps staff identifiers internal across lifecycle recruitment performance and development lists', function () {
    $portal=base_path('../portal-thetaxi/src/app/modules');
    $lifecycle=file_get_contents($portal.'/hr-lifecycle/components/lifecycle-cases/lifecycle-cases.component.html');
    $feedback=file_get_contents($portal.'/hr-lifecycle/components/recruitment/recruitment.component.html');
    $performance=file_get_contents($portal.'/hr-talent/components/performance/performance.component.html');
    $development=file_get_contents($portal.'/hr-talent/components/development/development.component.html');
    expect($lifecycle)->toContain('staff_first_name')->not->toContain("{{row.staff_id||")
        ->and($feedback)->toContain('reviewer_first_name')->not->toContain('Staff {{f.reviewer_staff_id}}')
        ->and($performance)->toContain('staff_first_name')->not->toContain('<td>{{row.staff_id}}</td>')
        ->and($development)->toContain('manager_first_name')->not->toContain('<td>{{row.manager_staff_id');
});

it('uses a tenant scoped authorized selector and readable labels for confidential wellness owners', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/EngagementController.php'));
    $routes=file_get_contents(base_path('routes/api.php'));
    $portal=base_path('../portal-thetaxi/src/app/modules/hr-engagement/components');
    $detail=file_get_contents($portal.'/wellness-detail/wellness-detail.component.html');
    $followups=file_get_contents($portal.'/wellness-followups/wellness-followups.component.html');
    expect($routes)->toContain("wellness/handler-options")->toContain("permission:hr.wellness.case.manage")
        ->and($controller)->toContain("abort_unless(\$data['company_id']===\$actor->company_id")
        ->toContain("can('hr.wellness.case.manage')")->toContain("'owner_label'")->toContain("'case_owner_label'")
        ->and($detail)->toContain('app-ui-managed-record-select')->toContain('case_owner_label')->not->toContain('Case owner Staff ID')
        ->and($followups)->toContain('app-ui-managed-record-select')->toContain('owner_label')->not->toContain('Owner Staff ID');
});
