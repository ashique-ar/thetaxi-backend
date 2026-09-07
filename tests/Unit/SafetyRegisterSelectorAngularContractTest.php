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

it('renders register relationships from bounded response labels without a raw Staff identifier fallback', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/SafetyController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-relations/components/safety-registers/safety-registers.component.ts'));

    expect($controller)->toContain("\$staffIds = \$inspections->pluck('lead_staff_id')")
        ->toContain("merge(\$hazards->pluck('owner_staff_id'))")
        ->toContain("merge(\$ppe->pluck('staff_id'))")
        ->toContain("if (\$canViewFitness)")
        ->toContain("'staff_labels' => \$staffLabels")
        ->toContain("Staff::withTrashed()")
        ->toContain("where('staff.company_id', \$a->company_id)")
        ->and($component)->toContain("'Unavailable Staff record'")
        ->not->toContain("{ code: id }")
        ->not->toContain('safetyHandlerOptions()')
        ->and($routes)->not->toContain("Route::get('handler-options', [SafetyController::class, 'handlerOptions'])");
});
