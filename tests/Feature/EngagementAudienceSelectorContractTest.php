<?php

it('uses bounded readable selectors for engagement Staff and organization audiences', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/EngagementController.php'));
    $announcement=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/announcement-form/announcement-form.component.html'));
    $survey=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/survey-form/survey-form.component.html'));

    expect($controller)->toContain('public function audienceOptions(')->toContain("Rule::in(['staff','organization_unit'])")->toContain("'per_page'=>['nullable','integer','min:1','max:50']")
        ->and($announcement)->toContain('recordType="organization_unit"')->toContain('recordType="staff"')->not->toContain('Organization unit IDs')->not->toContain('Individual Staff IDs')
        ->and($survey)->toContain('recordType="organization_unit"')->toContain('recordType="staff"')->not->toContain('Organization unit IDs')->not->toContain('Individual Staff IDs');
});

it('rejects inactive or cross-company explicit audience references', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/EngagementController.php'));
    expect($controller)->toContain("assertAudience(\$data['audience'],(string)\$actor->company_id)")
        ->toContain('Every audience Staff member must be active in your legal entity.')
        ->toContain('Every audience organization unit must be active in your legal entity.');
});
