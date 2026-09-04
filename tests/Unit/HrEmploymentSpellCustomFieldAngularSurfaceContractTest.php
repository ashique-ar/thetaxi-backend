<?php

it('wires the previously-missing employment-spell Angular surface into the Employee 360 page, reusing the same generic component already used for organization units and positions', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/people-detail.component.ts'));

    expect($component)
        ->toContain("import { SubjectCustomFieldValuesComponent } from './subject-custom-field-values.component';")
        ->toContain('<app-subject-custom-field-values ownerType="employment_spell" [ownerId]="spell.id"></app-subject-custom-field-values>')
        ->toContain("toggleSpellAttributes(spellId:string):void{this.attributesSpellId.set(this.attributesSpellId()===spellId?null:spellId)}")
        ->toContain('*hasPermission="\'hr.custom-fields.values.view\'"');
});

it('confirms the reused component already declares employment_spell as a supported owner type', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/hr-people.service.ts'));

    expect($service)->toContain("export type SubjectOwnerType = 'organization_unit' | 'position' | 'employment_spell';");
});
