<?php

it('registers internal legal-entity job and position administration routes', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::prefix('hr/organization')->middleware('ensure.internal')")
        ->toContain("Route::get('job-families'")
        ->toContain("Route::post('job-grades'")
        ->toContain("Route::put('designations/{designationId}'")
        ->toContain("Route::get('positions'")
        ->toContain('permission:hr.organization.manage');
});

it('governs job catalogues and positions with tenant references versions and replay checks', function () {
    $service = file_get_contents(app_path('Services/Hr/JobPositionAdministrationService.php'));

    expect($service)->toContain("'job_family' =>")
        ->toContain("'job_grade' =>")
        ->toContain("'designation' =>")
        ->toContain("where('company_id', \$companyId)->lockForUpdate()")
        ->toContain('Job catalogue codes are immutable; create a new record instead.')
        ->toContain('Position numbers are immutable; create a new position instead.')
        ->toContain('The job catalogue interval or status must continue to cover its active dependent records.')
        ->toContain('Idempotency key was reused with different job or position facts.')
        ->toContain("'before_snapshot'")
        ->toContain("'after_snapshot'");
});

it('derives position vacancies from current Staff assignments and blocks unsafe capacity changes', function () {
    $service = file_get_contents(app_path('Services/Hr/JobPositionAdministrationService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect($service)->toContain("DB::table('hr_employment_assignments')")
        ->toContain("'occupied_count'")
        ->toContain("'vacancy_count'")
        ->toContain("'availability_status'")
        ->toContain('Headcount cannot be reduced below current effective occupancy.')
        ->toContain('A position with current occupants cannot be made inactive.')
        ->and($controller)->toContain("'availability' => ['nullable', Rule::in(['vacant', 'partially_filled', 'filled', 'inactive'])]")
        ->toContain("leftJoinSub(\$occupancy, 'occupancy'")
        ->toContain("'custom_fields' => ['prohibited']");
});

it('uses rollback-safe legal-entity-qualified position numbers without inventing legacy effective dates', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_14_108000_govern_hr_job_and_position_administration.php'));

    expect($migration)->toContain("['company_id', 'position_number']")
        ->toContain("->date('effective_from')->nullable()")
        ->toContain('Refusing to remove governed HR job or position history.')
        ->toContain('Refusing rollback because legal entities now contain duplicate position numbers');
});
