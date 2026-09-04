<?php

use Database\Seeders\AllPermissionsSeeder;


it('contains every permission referenced by active backend middleware', function () {
    $appFiles = collect(iterator_to_array(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()))
    ))
        ->filter(fn (SplFileInfo $file) => $file->isFile() && $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file) => $file->getPathname());

    $backendSources = $appFiles
        ->merge(glob(base_path('routes/*.php')))
        ->map(fn (string $file) => file_get_contents($file))
        ->implode("\n");

    preg_match_all('/permission:([a-zA-Z0-9_.|_-]+)/', $backendSources, $matches);

    $routePermissions = collect($matches[1] ?? [])
        ->flatMap(fn (string $permissions) => explode('|', $permissions))
        ->filter()
        ->unique()
        ->sort()
        ->values();

    $missing = $routePermissions->diff(AllPermissionsSeeder::allPermissionNames())->values();

    expect($missing->all())->toBe([]);
});

it('keeps the canonical seeder additive and all old entry points centralized', function () {
    $canonicalSeeder = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    expect($canonicalSeeder)
        ->toContain('Permission::firstOrCreate')
        ->toContain('Role::firstOrCreate')
        ->toContain('givePermissionTo')
        ->not->toContain('truncate(')
        ->not->toContain('syncPermissions(')
        ->not->toContain('delete(');

    foreach ([
        'RolesAndPermissionsSeeder.php',
        'AdditionalPermissionsSeeder.php',
        'AddCorporatePermissionsSeeder.php',
        'CorporatePermissionsSeeder.php',
        'CollectionCommissionPermissionsSeeder.php',
        'RouteReferencedPermissionsSeeder.php',
        'SmsManagementPermissionsSeeder.php',
        'VehicleLeasingPermissionsSeeder.php',
    ] as $compatibilitySeederFile) {
        $compatibilitySeeder = file_get_contents(database_path("seeders/{$compatibilitySeederFile}"));

        expect($compatibilitySeeder)
            ->toContain('$this->call(AllPermissionsSeeder::class)')
            ->not->toContain('Permission::firstOrCreate')
            ->not->toContain('truncate(')
            ->not->toContain('syncPermissions(');
    }
});

it('includes module-specific permissions previously spread across separate seeders', function () {
    $permissions = AllPermissionsSeeder::allPermissionNames();

    expect($permissions)
        ->toContain('collection-commissions.pay')
        ->toContain('vehicle-leases.release')
        ->toContain('sms.settings.manage')
        ->toContain('corporates.manage')
        ->toContain('view_all_bookings')
        ->toContain('bookings.export')
        ->toContain('pages.delete')
        ->toContain('bookings.tracking_export');
});
