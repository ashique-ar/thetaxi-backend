<?php

it('replaces lifecycle subject UUID entry with tenant-scoped readable selectors', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/LifecycleController.php'));
    $component=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-lifecycle/components/lifecycle-cases/open-case-dialog.component.ts'));

    expect($controller)->toContain('public function caseSubjectOptions(')->toContain("Rule::in(['staff','application'])")->toContain("where('app.company_id',\$d['company_id'])")
        ->and($component)->toContain('UiManagedRecordSelectComponent')->toContain('endpoint="/hr/lifecycle/case-subject-options"')->not->toContain('Staff ID (leave blank')->not->toContain('Application ID (pre-hire only)');
});

it('requires exactly one lifecycle subject in both UI and API', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/LifecycleController.php'));
    $component=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-lifecycle/components/lifecycle-cases/open-case-dialog.component.ts'));
    expect($controller)->toContain("'required_without:application_id','prohibited_with:application_id'")
        ->and($component)->toContain("return staff === application ? { subject: true } : null;");
});
