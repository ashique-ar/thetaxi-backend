<?php

it('reconciles legacy saved location references in wrapped and raw form configs', function (): void {
    $migration = file_get_contents(database_path('migrations/2026_10_31_000030_reconcile_wrapped_dynamic_form_location_refs.php'));

    expect($migration)
        ->toContain("isset(\$config['fields'])", "\$fieldsKey ? \$config[\$fieldsKey] : \$config")
        ->toContain("array_key_exists('company_id', \$option)")
        ->toContain("\$option['location_id'] = \$location->id", "\$option['location_source'] = 'saved_location'")
        ->toContain("\$option['location_source'] = 'configured'", "unset(\$option['company_id'])")
        ->toContain('Cannot reconcile conflicting location IDs', 'Cannot preserve missing saved location')
        ->toContain("\$config[\$fieldsKey] = \$fields")
        ->toContain('Dynamic form saved locations cannot be converted back to company scope.');
});
