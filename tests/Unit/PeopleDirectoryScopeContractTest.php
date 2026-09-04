<?php

it('keeps the People directory workforce-wide and hierarchy scoped', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));
    $access=file_get_contents(app_path('Services/Hr/PeopleAccessService.php'));
    $routes=file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::prefix('hr/employees')->middleware('ensure.internal')")
        ->and($controller)
        ->toContain("'staff_category'=>$staff->staff_type")
        ->toContain('Sales Profile enrollment and eligibility are managed separately')
        ->not->toContain('SalesProfile::query()')
        ->and($access)
        ->toContain("where('company_id', $actorStaff->company_id)")
        ->toContain("can('hr.people.view-team')")
        ->toContain("can('hr.people.view-all')")
        ->toContain("orWhereIn('id', $reportingMembers)")
        ->toContain("whereIn('line_type', ['primary', 'dotted_line'])")
        ->toContain("from('hr_reporting_lines as governed_lines')");
});

it('rechecks People mutation subjects and related-record ownership', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect(substr_count($controller,'$this->access->authorize($request->user(),$staff)'))->toBeGreaterThanOrEqual(7)
        ->and($controller)
        ->toContain('The employment spell does not belong to this employee.')
        ->toContain('The parent unit must belong to the same legal entity.')
        ->toContain('The manager must belong to the employee legal entity.')
        ->toContain("config('hr.features.people_core',false)");
});
