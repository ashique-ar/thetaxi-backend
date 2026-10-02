<?php

it('bounds PPE lookup by permission tenant employee date and current custody state', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/Hr/SafetyController.php'));
    $lookup = explode('public function incidentStaffLabels', explode('public function ppeCustodyOptions', $source)[1])[0];
    $routes = file_get_contents(base_path('routes/api.php'));
    expect($routes)->toContain("Route::get('ppe-custody-options', [SafetyController::class, 'ppeCustodyOptions'])->middleware('permission:hr.safety.manage')");
    expect($lookup)->toContain("'max:50'", "'selected_id'", "->where('staff_id', \$data['staff_id'])",
        "->where('company_id', \$actor->company_id)", "->where('status', 'assigned')->whereNull('returned_at')",
        "->whereDate('assigned_at', '<=', \$data['issued_at'])", "'label' => \$row->item_name");
    expect($lookup)->not->toContain('encrypted_details', 'condition_snapshot');
});

it('makes issuance and audit atomic and revalidates custody under locks with conflict checked retries', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/Hr/SafetyController.php'));
    $write = preg_replace('/\\s+/', '', explode('public function report', explode('public function issuePpe', $source)[1])[0]);
    expect($write)->toContain('returnDB::transaction(', "->where('status','assigned')->whereNull('returned_at')",
        "->whereDate('assigned_at','<=',\$d['issued_at'])->lockForUpdate()->first()",
        "hash_equals(\$existing->issuance_checksum,\$checksum)", "\$id=\$d['idempotency_key']",
        "\$existing->company_id===\$a->company_id", "\$existing->issued_by===\$r->user()->id",
        "\$this->registerEvent(");
    expect(strpos($write, 'if($existing)'))->toBeLessThan(strpos($write, "\$this->activeStaff("));
});

it('uses a readable custody selector and clears it when employee or date changes', function () {
    $ui = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-relations/components/safety-registers/dialogs/ppe-form-dialog.component.ts'));
    expect($ui)->toContain('endpoint="/hr/safety/ppe-custody-options"', '[queryParams]="custodyQuery"',
        "custody_assignment_id.setValue('', { emitEvent: false })", 'idempotency_key: this.idempotencyKey');
    expect($ui)->not->toContain('Custody assignment UUID', '<input matInput formControlName="custody_assignment_id">');
});

it('searches employees through the PPE permission boundary instead of passing a preloaded list', function () {
    $ui = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-relations/components/safety-registers/dialogs/ppe-form-dialog.component.ts'));
    $routes = file_get_contents(base_path('routes/api.php'));
    expect($ui)->toContain('endpoint="/hr/safety/ppe-employee-options"', 'label="Employee"')
        ->not->toContain('data.staffMembers', 'MAT_DIALOG_DATA', '<mat-select');
    expect($routes)->toContain("Route::get('ppe-employee-options', [SafetyController::class, 'ppeEmployeeOptions'])->middleware('permission:hr.safety.manage')");
});
