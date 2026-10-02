<?php

it('uses the authorized organization reference selector in HR parent and job catalog forms', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));
    $organizationTemplate = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/organization-administration/organization-administration.component.html'));
    $organizationComponent = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/organization-administration/organization-administration.component.ts'));
    $jobTemplate = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/job-position-administration/job-position-administration.component.html'));
    $jobComponent = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/job-position-administration/job-position-administration.component.ts'));
    $sharedSelector = file_get_contents(base_path('../portal-thetaxi/src/app/shared/components/ui/managed-record-select/managed-record-select.component.ts'));

    expect($routes)->toContain("'reference-options', [PeopleCoreController::class, 'organizationReferenceOptions']")
        ->and($routes)->toContain("->middleware('permission:hr.organization.view')")
        ->and($controller)->toContain("Rule::in(['organization_unit', 'job_family', 'job_grade', 'designation'])")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->and($organizationTemplate)->toContain('endpoint="/hr/organization/reference-options" recordType="organization_unit"')
        ->and($organizationTemplate)->not->toContain('<mat-select formControlName="parent_id">')
        ->and($organizationComponent)->toContain('unitParentQueryParams = { exclude_id: unit.id }')
        ->and($jobTemplate)->toContain('recordType="job_family"')
        ->and($jobTemplate)->toContain('recordType="job_grade"')
        ->and($jobTemplate)->toContain('recordType="designation"')
        ->and($jobTemplate)->toContain('[queryParams]="designationReferenceParams"')
        ->and($jobTemplate)->toContain('[queryParams]="positionReferenceParams"')
        ->and($jobTemplate)->not->toContain('<mat-select formControlName="organization_unit_id">')
        ->and($jobTemplate)->not->toContain('<mat-select formControlName="designation_id">')
        ->and($jobTemplate)->not->toContain('<mat-select formControlName="job_family_id">')
        ->and($jobTemplate)->not->toContain('<mat-select formControlName="job_grade_id">')
        ->and($jobComponent)->toContain('UiManagedRecordSelectComponent')
        ->and($sharedSelector)->toContain('@Input() required = false;')
        ->and($sharedSelector)->toContain('[required]="required"');
});
