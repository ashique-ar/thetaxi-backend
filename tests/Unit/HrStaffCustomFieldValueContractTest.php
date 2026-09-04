<?php

it('owns Staff custom-field values behind internal People scope and separate permissions', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));
    $seeder = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    expect($routes)->toContain("Route::prefix('hr/employees')->middleware('ensure.internal')")
        ->toContain("Route::put('{staffId}/custom-fields/{definitionId}'")
        ->toContain('permission:hr.custom-fields.values.manage')
        ->and($controller)->toContain("authorize(\$request->user(),\$staff)")
        ->toContain("'custom_field_values'=>\$this->customFieldValues->listForStaff(\$staff,\$request->user())")
        ->and($seeder)->toContain("'hr.custom-fields.values.view'")
        ->toContain("'hr.custom-fields.sensitive.view'")
        ->toContain("'hr.custom-fields.legal.view'");
});

it('encrypts values and validates every bounded type without persisting plaintext', function () {
    $service = file_get_contents(app_path('Services/Hr/StaffCustomFieldValueService.php'));

    expect($service)->toContain('Crypt::encryptString($this->canonicalValue($value))')
        ->toContain("'decimal' => \$this->decimalValue(\$value)")
        ->toContain("'datetime' => \$this->dateTimeValue(\$value)")
        ->toContain("'multi_select' => \$this->multiSelectValue(\$value, \$rules)")
        ->toContain('Decimal custom fields require a bounded exact decimal string')
        ->toContain('Datetime custom fields require an explicit timezone offset.')
        ->toContain('This required custom field cannot be empty.')
        ->not->toContain("'value' => \$value, 'updated_by'");
});

it('fails closed on stale definition value integrity backdating and legacy ciphertext', function () {
    $service = file_get_contents(app_path('Services/Hr/StaffCustomFieldValueService.php'));

    expect($service)->toContain('Custom-field definition version is stale.')
        ->toContain('Custom-field value version is stale.')
        ->toContain('Backdating a Staff custom-field value requires separate permission.')
        ->toContain('A Staff custom-field value failed its integrity check.')
        ->toContain("'legacy_unverified' => (bool) \$legacy")
        ->toContain("'definition_stale' => (bool)");
});

it('filters classifications server-side and audits every revealed value', function () {
    $service = file_get_contents(app_path('Services/Hr/StaffCustomFieldValueService.php'));

    expect($service)->toContain("'hr_private' => \$actor->can('hr.custom-fields.sensitive.view')")
        ->toContain("'legal' => \$actor->can('hr.custom-fields.legal.view')")
        ->toContain("default => \$actor->can('hr.custom-fields.values.view')")
        ->toContain("DB::table('hr_custom_field_value_access_events')->insert")
        ->toContain("'access_type' => 'viewed'");
});

it('keeps Legal-classified values fail closed until baseline role ownership is approved', function () {
    $seeder = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    expect($seeder)->toContain("'hr.custom-fields.legal.view'");
    preg_match("/'hr-manager'\s*=>\s*\[(.*?)\],\s*'hr-integration-adapter'/s", $seeder, $managerRole);
    expect($managerRole[1] ?? null)->not->toBeNull()->not->toContain('hr.custom-fields.legal.view');
});

it('retains encrypted before and after history and refuses destructive rollback after use', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_14_111000_govern_hr_staff_custom_field_values.php'));

    expect($migration)->toContain("Schema::create('hr_custom_field_value_events'")
        ->toContain("Schema::create('hr_custom_field_value_access_events'")
        ->toContain("'before_encrypted_value'")
        ->toContain("'after_encrypted_value'")
        ->toContain('Custom-field value or access history exists')
        ->toContain("Schema::dropIfExists('hr_custom_field_value_events')");
});
