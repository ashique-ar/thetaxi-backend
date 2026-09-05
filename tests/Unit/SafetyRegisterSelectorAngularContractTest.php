<?php

it('uses authorized searchable register assignments without preloaded Staff lists', function () {
    $root = base_path('../portal-thetaxi/src/app/modules/hr-relations/components/safety-registers/dialogs/');
    $hazard = file_get_contents($root.'hazard-form-dialog.component.ts');
    $inspection = file_get_contents($root.'inspection-form-dialog.component.ts');
    expect($hazard)->toContain('endpoint="/hr/safety/register-employee-options"', 'Leave unassigned', 'owner_staff_id: raw.owner_staff_id || null')
        ->not->toContain('data.staffMembers');
    expect($inspection)->toContain('endpoint="/hr/safety/handler-candidates"', 'recordType="investigator"')
        ->not->toContain('data.investigators', 'MAT_DIALOG_DATA');
    foreach ([$hazard, $inspection] as $source) {
        expect($source)->toContain('UiManagedRecordSelectComponent', '[companyId]="companyId"', 'idempotency_key: this.idempotencyKey');
    }
});

it('uses the confidential fitness employee selector and an internal retry key', function () {
    $ui = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-relations/components/safety-registers/dialogs/fitness-form-dialog.component.ts'));
    expect($ui)->toContain('endpoint="/hr/safety/fitness-employee-options"', '[companyId]="companyId"', 'idempotency_key: this.idempotencyKey')
        ->not->toContain('data.staffMembers');
});
