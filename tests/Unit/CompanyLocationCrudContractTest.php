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
