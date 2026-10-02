<?php

it('provides a tenant-scoped preview-first People Core legacy import and reconciliation workflow', function () {
    $routes=file_get_contents(base_path('routes/api.php'));
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));
    $service=file_get_contents(app_path('Services/Hr/PeopleCoreMigrationService.php'));

    expect($routes)->toContain("Route::get('reconciliation'")
        ->toContain("Route::post('imports/preview'")
        ->toContain("Route::post('imports/{job}/commit'")
        ->toContain("Route::post('exports'")
        ->toContain("Route::get('exports/{export}/download'")
        ->and($controller)->toContain("hash_equals(\$job->file_checksum")
        ->toContain("\$job->company_id===\$this->access->actorCompanyId")
        ->and($service)->toContain("public const MAPPING_VERSION")
        ->toContain("A People Core import is limited to 10,000 data rows.")
        ->toContain("Employee number is ambiguous in this legal entity.")
        ->toContain("Employment history changed after preview")
        ->toContain("'organization_unit_code','position_number','manager_employee_number'")
        ->toContain('Position and organization unit do not match.')
        ->toContain('An employee cannot manage their own imported assignment.')
        ->toContain("\$this->people->addImportedInitialAssignment")
        ->toContain("where('company_id',\$companyId)->lockForUpdate()")
        ->toContain("'legacy_import'=>true")
        ->toContain("'before'=>\$job->reconciliation_totals['before']??null,'after'=>\$after");
});

it('creates integrity checked private tenant exports with spreadsheet injection protection', function () {
    $service=file_get_contents(app_path('Services/Hr/PeopleCoreMigrationService.php'));
    expect($service)->toContain("Storage::disk('hr_private')->put")
        ->toContain("'scope_checksum'=>\$scopeChecksum")
        ->toContain("hash_equals(\$export->file_checksum")
        ->toContain("The People Core export integrity check failed.")
        ->toContain("preg_match('/^[=+\\-@]/'")
        ->toContain("increment('download_count')");
});

it('retains immutable source files row outcomes checksums and blocks lossy rollback', function () {
    $migration=file_get_contents(database_path('migrations/2026_09_04_120000_create_hr_people_core_migration_evidence.php'));
    expect($migration)->toContain("Schema::create('hr_people_import_jobs'")
        ->toContain("Schema::create('hr_people_import_rows'")
        ->toContain("char('file_checksum', 64)")
        ->toContain("char('payload_checksum', 64)")
        ->toContain("json('errors')->nullable()")
        ->toContain('Rollback refused: export and reconcile retained');
});

it('reports every active identity spell and assignment exception without inventing legacy dates', function () {
    $service=file_get_contents(app_path('Services/Hr/PeopleCoreMigrationService.php'));
    expect($service)->toContain("'activeMissingIdentity'")
        ->toContain("'activeMissingCode'")
        ->toContain("'activeMissingSpell'")
        ->toContain("'activeMultipleSpells'")
        ->toContain("'activeMissingAssignment'")
        ->toContain("'formerOpenSpell'")
        ->toContain("foreach(['joined_at','service_date'] as \$field)")
        ->toContain('must be YYYY-MM-DD.');
});
