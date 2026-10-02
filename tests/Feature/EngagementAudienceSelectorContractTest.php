<?php

it('uses bounded readable selectors for engagement Staff and organization audiences', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/EngagementController.php'));
    $announcement=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/announcement-form/announcement-form.component.html'));
    $survey=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/survey-form/survey-form.component.html'));

    expect($controller)->toContain('public function audienceOptions(')->toContain("Rule::in(['staff','organization_unit','location','staff_type'])")->toContain("'per_page'=>['nullable','integer','min:1','max:50']")
        ->and($announcement)->toContain('recordType="organization_unit"')->toContain('recordType="staff"')->toContain('recordType="location"')->toContain('recordType="staff_type"')->not->toContain('Organization unit IDs')->not->toContain('Individual Staff IDs')->not->toContain('formControlName="audience_location_codes">')
        ->and($survey)->toContain('recordType="organization_unit"')->toContain('recordType="staff"')->toContain('recordType="location"')->toContain('recordType="staff_type"')->toContain('crypto.randomUUID()')->not->toContain('Question ID')->not->toContain('Organization unit IDs')->not->toContain('Individual Staff IDs')->not->toContain('formControlName="audience_location_codes">');
});

it('rejects inactive or cross-company explicit audience references', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/EngagementController.php'));
    expect($controller)->toContain("assertAudience(\$data['audience'],(string)\$actor->company_id)")
        ->toContain('Every audience Staff member must be active in your legal entity.')
        ->toContain('Every audience organization unit must be active in your legal entity.')
        ->toContain('Every audience Staff type must be active in your legal entity.')
        ->toContain('Every audience location must be configured in your legal entity.');
});

it('uses bounded exact-hydrated wellness programme choices in the referral form', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/EngagementController.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/wellness-request/wellness-request.component.html'));
    $logic = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/wellness-request/wellness-request.component.ts'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/hr-engagement.service.ts'));

    expect($controller)->toContain("'selected_id' => ['nullable', 'uuid']")
        ->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain('$this->audience($query, $actor)')
        ->and($component)->toContain('endpoint="/hr/engagement/wellness/programs"')
        ->not->toContain('programs()')
        ->and($logic)->toContain('UiManagedRecordSelectComponent')
        ->not->toContain('wellnessPrograms()')
        ->and($service)->not->toContain('wellnessPrograms()');
});
