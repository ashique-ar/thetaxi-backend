<?php

it('binds every direct Staff record action to the Staff policy', function () {
    $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
    $service = file_get_contents(app_path('Services/StaffAccessService.php'));
    $policy = file_get_contents(app_path('Policies/StaffPolicy.php'));

    expect($provider)
        ->toContain('Gate::policy(Staff::class, StaffPolicy::class)')
        ->and($service)
        ->toContain('Gate::forUser($actor)->authorize($ability, $staff)')
        ->toContain("'edit', 'update' => 'update'")
        ->toContain("'terminate', 'delete' => 'terminate'")
        ->and($policy)
        ->toContain("allows(\$user, \$staff, 'view')")
        ->toContain("allows(\$user, \$staff, 'edit')")
        ->toContain("allows(\$user, \$staff, 'terminate')");
});

it('keeps direct Staff authorization fail closed for unknown actions', function () {
    $service = file_get_contents(app_path('Services/StaffAccessService.php'));

    expect($service)
        ->toContain('default => null')
        ->toContain("abort_unless(\$ability !== null, 403, 'The requested Staff action is not authorized.')");
});

it('retains own team legal-entity and all scope checks behind the policy', function () {
    $service = file_get_contents(app_path('Services/StaffAccessService.php'));

    expect($service)
        ->toContain('public function allows(User $actor, Staff $staff, string $action): bool')
        ->toContain('staff.{$action}-all')
        ->toContain('staff.{$action}-legal-entity')
        ->toContain('staff.{$action}-team')
        ->toContain('$staff->user_id === $actor->id');
});

it('pairs direct Staff routes with their operation permission before row policy evaluation', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::get('staff/{staff}', [StaffController::class, 'show'])")
        ->toContain("->middleware('permission:staff.view')")
        ->toContain("Route::match(['put', 'patch'], 'staff/{staff}', [StaffController::class, 'update'])")
        ->toContain("->middleware('permission:staff.edit')")
        ->toContain("Route::delete('staff/{staff}', [StaffController::class, 'destroy'])")
        ->toContain("->middleware('permission:staff.terminate')")
        ->toContain("Route::post('staff/{staff}/terminate', [StaffController::class, 'destroy'])");
});
