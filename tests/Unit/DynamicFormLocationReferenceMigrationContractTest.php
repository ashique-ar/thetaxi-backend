<?php

it('renames dynamic form company IDs into saved location references without dropping locations', function (): void {
    $migration = file_get_contents(database_path('migrations/2026_10_31_000023_rename_dynamic_form_company_location_refs.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Service/ServiceFormConfigController.php'));
    $hydrator = file_get_contents(app_path('Services/DynamicServiceConfigurationService.php'));
    $publicSelector = file_get_contents(resource_path('views/components/predefined-location-selector.blade.php'));
    $bookingController = file_get_contents(app_path('Http/Controllers/BookingController.php'));

    expect($migration)
        ->toContain('$option[\'location_id\'] = $locationId')
        ->toContain('unset($option[\'company_id\'])')
        ->toContain('conflicting location IDs')
        ->and($controller)
        ->toContain("options.*.location_id")
        ->not->toContain("options.*.company_id")
        ->and($hydrator)
        ->toContain('hydrateSavedLocations')
        ->toContain('$source === \'saved_location\'')
        ->not->toContain('$source === \'company\'')
        ->and($publicSelector)
        ->toContain('_location_id', 'data-location-id')
        ->not->toContain('getDefaultCompany', '_company_id')
        ->and($bookingController)
        ->toContain('{$prefix}_location_id')
        ->not->toContain('{$prefix}_company_id');
});
