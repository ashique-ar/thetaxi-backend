<?php

it('exposes selectable operating locations without a default-tenant field', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/CompanyController.php'));
    $model = file_get_contents(app_path('Models/Company.php'));

    expect($controller)->toContain("select(['id', 'name', 'is_active'])", 'Location options loaded')
        ->not->toContain('default_company_id', 'is_default', 'active_default_guard')
        ->and($model)->not->toContain("'is_default'");
});

it('does not change location CRUD based on a default-company marker', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/CompanyController.php'));

    expect($controller)->not->toContain('is_default', 'default_company_id', 'active_default_guard');
});

it('labels saved-location permissions as locations while retaining their stable keys', function () {
    $permission = new \\Spatie\\Permission\\Models\\Permission();
    $permission->forceFill(['id' => 1, 'name' => 'companies.view', 'guard_name' => 'api']);
    $formatted = app(\\App\\Services\\PermissionRegistry::class)->formatPermission($permission);

    expect(config('permissions.modules.companies.label'))->toBe('Locations')
        ->and($formatted['name'])->toBe('companies.view')
        ->and($formatted['module_label'])->toBe('Locations')
        ->and($formatted['display_name'])->toBe('View Locations');
});
