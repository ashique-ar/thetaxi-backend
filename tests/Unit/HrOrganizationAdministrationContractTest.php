<?php

it('registers separately permissioned internal organization administration routes',function(){
    $routes=file_get_contents(base_path('routes/api.php'));$seeder=file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));
    expect($routes)->toContain("Route::prefix('hr/organization')->middleware('ensure.internal')")->toContain("permission:hr.organization.manage")->toContain("permission:hr.custom-fields.manage")
        ->and($seeder)->toContain("'hr.organization.view'")->toContain("'hr.organization.manage'")->toContain("'hr.custom-fields.view'")->toContain("'hr.custom-fields.manage'");
});

it('governs organization changes with locks versions history and replay checks',function(){
    $service=file_get_contents(app_path('Services/Hr/OrganizationAdministrationService.php'));$migration=file_get_contents(database_path('migrations/2026_08_14_107000_govern_hr_organization_administration.php'));
    expect($service)->toContain('lockForUpdate()')->toContain('expected_version')->toContain('Organization hierarchy cannot contain a cycle.')
        ->toContain('The organization-unit interval or status must continue to cover its active child units and positions.')
        ->toContain('Idempotency key was reused with different organization facts.')->toContain("'before_snapshot'")->toContain("'after_snapshot'")
        ->toContain('Organization command replay is outside your legal entity.')
        ->and($migration)->toContain("Schema::create('hr_organization_change_events'")->toContain("'aggregate_type', 'aggregate_id', 'aggregate_version'")->toContain('Refusing to remove retained HR organization history.')->toContain("Schema::dropIfExists('hr_organization_change_events')");
});

it('keeps custom fields metadata driven classified and option bounded',function(){
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));
    expect($controller)->toContain("Rule::in(['staff','organization_unit','position','employment_spell'])")->toContain("Rule::in(['employee','manager','internal','hr_private','legal'])")
        ->toContain("'custom_fields'=>['prohibited']")->toContain('Select custom fields require at least one option.')->toContain('Only select custom fields may define options.');
    expect(file_get_contents(app_path('Services/Hr/OrganizationAdministrationService.php')))->toContain('Custom-field owner, key and data type are immutable; create a new definition instead.');
});
