<?php

it('owns acting appointments in an internal workforce route with separate permissions', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $seeder = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));
    $legacy = file_get_contents(app_path('Http/Controllers/Api/Hr/LifecycleController.php'));

    expect($routes)->toContain("Route::prefix('hr/organization')->middleware('ensure.internal')")
        ->toContain("Route::post('acting-appointments'")
        ->toContain("Route::post('acting-appointments/{appointmentId}/approve'")
        ->toContain('permission:hr.acting-appointments.manage')
        ->toContain('permission:hr.acting-appointments.approve')
        ->and($seeder)->toContain("'hr.acting-appointments.view'")->toContain("'hr.acting-appointments.approve'")
        ->and($legacy)->not->toContain("'acting_appointment','manager_change'");
});

it('freezes a finite acting assignment and exact restoration without compensation or Sales mutation', function () {
    $service = file_get_contents(app_path('Services/Hr/ActingAppointmentAdministrationService.php'));

    expect($service)->toContain("'compensation_change_included' => false")
        ->toContain("'payroll_change_included' => false")
        ->toContain("'delegated_approval_authority_included' => false")
        ->toContain("'sales_profile_or_hierarchy_change_included' => false")
        ->toContain("insertAssignment(\$row, \$actingSnapshot, 'acting'")
        ->toContain("insertAssignment(\$row, \$restoreSnapshot, 'primary'")
        ->toContain('projectAssignmentManagers($acting, $actorUserId)')
        ->toContain('projectAssignmentManagers($restoration, $actorUserId)');
});

it('fails closed on overlap capacity stale source and maker-checker violations', function () {
    $service = file_get_contents(app_path('Services/Hr/ActingAppointmentAdministrationService.php'));

    expect($service)->toContain('already has an overlapping acting appointment')
        ->toContain('no governed headcount capacity for the complete interval')
        ->toContain('source assignment changed after this request was prepared')
        ->toContain('source assignment must extend beyond the acting restoration boundary')
        ->toContain('Another assignment is already scheduled inside the acting or restoration boundary.')
        ->toContain('requester cannot approve the same acting appointment')
        ->toContain('Acting-appointment version is stale.')
        ->toContain('Acting-appointment key was reused with different evidence.');
});

it('scopes list and reference names through current People access and retains employee history', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/ActingAppointmentController.php'));
    $people = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect($controller)->toContain("scope(Staff::query()->select('staff.id'), \$request->user())")
        ->toContain("whereIn('appointment.staff_id', \$authorizedStaff)")
        ->toContain("whereIn('staff.id', \$authorizedStaff)")
        ->toContain("authorize(\$request->user(), Staff::query()->whereKey(\$appointment->staff_id)->firstOrFail())")
        ->and($people)->toContain("'acting_appointments' => \$actingAppointments,");
});

it('retains appointment events and refuses destructive rollback after use', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_14_110000_govern_hr_acting_appointments.php'));

    expect($migration)->toContain("Schema::create('hr_acting_appointments'")
        ->toContain("Schema::create('hr_acting_appointment_events'")
        ->toContain('Acting-appointment history exists')
        ->toContain("Schema::dropIfExists('hr_acting_appointment_events')");
});
