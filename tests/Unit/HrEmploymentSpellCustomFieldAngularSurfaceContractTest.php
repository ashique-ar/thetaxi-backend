<?php

it('wires the previously-missing employment-spell Angular surface into the Employee 360 page, reusing the same generic component already used for organization units and positions', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/people-detail/people-detail.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/people-detail/people-detail.component.html'));

    expect($component)
        ->toContain("import { SubjectCustomFieldValuesComponent } from '../subject-custom-field-values/subject-custom-field-values.component';")
        ->toContain('toggleSpellAttributes(spellId: string)')
        ->toContain('this.attributesSpellId.set(');
    expect($template)
        ->toContain('ownerType="employment_spell"')
        ->toContain('[ownerId]="spell.id"')
        ->toContain('*hasPermission="\'hr.custom-fields.values.view\'"');
});

it('confirms the reused component already declares employment_spell as a supported owner type', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/hr-people.service.ts'));

    expect($service)->toContain("export type SubjectOwnerType = 'organization_unit' | 'position' | 'employment_spell';");
});
