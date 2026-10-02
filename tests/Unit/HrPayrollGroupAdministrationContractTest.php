<?php

it('governs payroll groups as a versioned, legal-entity-scoped, idempotent register reusing the shared organization event ledger', function () {
    $service = file_get_contents(app_path('Services/Hr/OrganizationAdministrationService.php'));

    expect($service)
        ->toContain('public function createPayrollGroup(array $data,string $companyId,string $actorUserId):array')
        ->toContain('public function updatePayrollGroup(string $groupId,array $data,string $companyId,string $actorUserId):array')
        ->toContain("if(\$replay=\$this->replay(\$data['idempotency_key'],\$checksum,\$companyId,'payroll_group'))return\$replay;")
        ->toContain("abort_unless((int)\$row->version===(int)\$data['expected_version'],409,'Payroll group version is stale.');")
        ->toContain("abort_if(DB::table('hr_payroll_groups')->where('company_id',\$companyId)->where('code',\$payload['code'])->exists(),409,");
});

it('rejects payroll-group rollback once any governed change event exists', function () {
    $migration = file_get_contents(base_path('database/migrations/2026_08_15_100000_create_hr_payroll_groups.php'));

    expect($migration)->toContain("where('aggregate_type', 'payroll_group')");
});

it('exposes payroll-group administration under the existing hr.organization permissions without minting new ones', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::get('payroll-groups', [PeopleCoreController::class,'payrollGroups'])->middleware('permission:hr.organization.view');")
        ->toContain("Route::post('payroll-groups', [PeopleCoreController::class,'storePayrollGroup'])->middleware('permission:hr.organization.manage');")
        ->toContain("Route::put('payroll-groups/{groupId}', [PeopleCoreController::class,'updatePayrollGroup'])->whereUuid('groupId')->middleware('permission:hr.organization.manage');");
});
