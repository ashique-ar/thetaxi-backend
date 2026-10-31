<?php

it('removes the default tenant marker while retaining company location data', function (): void {
    $migration = file_get_contents(database_path('migrations/2026_10_31_000022_remove_company_default_tenant_flag.php'));
    $company = file_get_contents(app_path('Models/Company.php'));
    $resource = file_get_contents(app_path('Http/Resources/Company/CompanyResource.php'));

    expect($migration)
        ->toContain("'is_default'")
        ->toContain("'active_default_guard'")
        ->toContain('preserveLegacyDefaultFormLocations()')
        ->toContain('$fieldsKey = isset($config[\'fields\']) && is_array($config[\'fields\']) ? \'fields\' : null;')
        ->toContain('$config[$fieldsKey] = $fields;')
        ->toContain('$option[\'location_source\'] = \'saved_location\'')
        ->toContain('$option[\'location_id\'] = $location->id')
        ->and($company)
        ->not->toContain('getDefaultCompany', 'active_default_guard', 'function vehicles()', 'function users()')
        ->and($resource)
        ->toContain("'address' => \$this->address", "'latitude' => \$this->latitude", "'longitude' => \$this->longitude");
});
