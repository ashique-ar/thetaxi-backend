<?php

it('binds timeline spells to the requested Staff identity', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect($controller)
        ->toContain("whereKey(\$data['employment_spell_id'])->where('staff_id', \$staff->id)")
        ->toContain('The timeline employment spell does not belong to this employee.')
        ->not->toContain('company_id');
});

it('requires active HR evidence files installation-wide for employee records', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($controller)
        ->toContain("where('id', \$data['evidence_file_id'])->where('domain', 'hr')->whereNull('deleted_at')")
        ->toContain('Employee-record evidence must be an active HR file.')
        ->not->toContain('company_id')
        ->and($service)
        ->toContain("where('id',\$data['evidence_file_id'])->where('domain','hr')->whereNull('deleted_at')->lockForUpdate()->first()")
        ->not->toContain('company_id');
});

it('rehire validation preserves Staff identity and active non-self managers across locations', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($controller)
        ->toContain("abort_if(\$assignment['manager_staff_id'] === \$staff->id")
        ->toContain("whereKey(\$assignment['manager_staff_id'])->whereNull('employment_ended_at')->exists()")
        ->toContain('An active manager must be an active Staff member.')
        ->not->toContain('company_id')
        ->and($service)
        ->toContain("\$staff=Staff::withTrashed()->lockForUpdate()->findOrFail(\$case->staff_id)")
        ->not->toContain('company_id');
});

it('serializes rehire preparation before idempotency checks and retains NIC privacy', function () {
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($service)
        ->toContain('return DB::transaction(function()use($staff,$data,$actorUserId)')
        ->toContain("Staff::withTrashed()->whereKey(\$staff->id)->lockForUpdate()->firstOrFail()")
        ->toContain("where('idempotency_key',\$data['idempotency_key'])->lockForUpdate()->first()")
        ->toContain("'nic_fingerprint'=>\$staff->nic_fingerprint")
        ->not->toContain("'nic'=>\$staff->nic", 'company_id');
});
