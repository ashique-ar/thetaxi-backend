<?php

it('binds timeline spell filters to the requested employee and company', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect($controller)
        ->toContain("whereKey(\$data['employment_spell_id'])->where('staff_id', \$staff->id)->where('company_id', \$staff->company_id)")
        ->toContain('The timeline employment spell does not belong to this employee.');
});

it('binds employee record evidence to active HR evidence in the employee company', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($controller)
        ->toContain("where('id', \$data['evidence_file_id'])->where('company_id', \$staff->company_id)->where('domain', 'hr')->whereNull('deleted_at')")
        ->toContain('Employee-record evidence must be an active HR file from the same legal entity.')
        ->and($service)
        ->toContain("where('id',\$data['evidence_file_id'])->where('company_id',\$staff->company_id)->where('domain','hr')->whereNull('deleted_at')->lockForUpdate()->first()");
});

it('revalidates rehire company and active non-self manager in controller and transaction', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($controller)
        ->toContain("abort_unless(\$staff->company_id === \$case->company_id")
        ->toContain("abort_if(\$assignment['manager_staff_id'] === \$staff->id")
        ->toContain("whereNull('employment_ended_at')")
        ->and($service)
        ->toContain("abort_unless(\$staff->company_id===\$case->company_id");
});

it('never copies decrypted NIC into the plaintext rehire snapshot', function () {
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($service)
        ->toContain("'nic_fingerprint'=>\$staff->nic_fingerprint")
        ->not->toContain("'nic'=>\$staff->nic");
});
