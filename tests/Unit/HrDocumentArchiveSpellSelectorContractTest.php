<?php

it('uses the authorized searchable spell selector in the Employee 360 archive filter', function () {
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/people-detail/people-detail.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/people-detail/people-detail.component.ts'));

    expect($template)
        ->toContain('formControlName="employment_spell_id"')
        ->toContain("'/hr/employees/' + staffId + '/employment-spell-options'")
        ->not->toContain('<mat-select formControlName="employment_spell_id">');
    expect($component)->toContain('UiManagedRecordSelectComponent');
});
