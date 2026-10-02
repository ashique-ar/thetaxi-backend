<?php

it('registers reporting-line permissions and internal legal-entity routes', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $seeder = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    expect($routes)->toContain("Route::prefix('hr/organization')->middleware('ensure.internal')")
        ->toContain("Route::get('reporting-lines'")
        ->toContain("Route::post('reporting-lines'")
        ->toContain("Route::post('reporting-lines/{lineId}/end'")
        ->toContain('permission:hr.reporting-lines.view')
        ->toContain('permission:hr.reporting-lines.manage')
        ->and($seeder)->toContain("'hr.reporting-lines.view'")->toContain("'hr.reporting-lines.manage'");
});

it('governs effective reporting lines with overlap cycle employment and replay safeguards', function () {
    $service = file_get_contents(app_path('Services/Hr/ReportingLineAdministrationService.php'));

    expect($service)->toContain('An employee cannot report to themselves.')
        ->toContain('employment must cover the complete reporting-line interval.')
        ->toContain('A reporting line of this type already overlaps the requested interval.')
        ->toContain('Reporting hierarchy cannot contain an effective cycle.')
        ->toContain('Reporting hierarchy is too complex to validate safely.')
        ->toContain('Reporting-line idempotency key was reused with different facts.')
        ->toContain("'before_snapshot'")
        ->toContain("'after_snapshot'");
});

it('uses only primary and dotted lines for People team scope and projects approved assignment managers', function () {
    $access = file_get_contents(app_path('Services/Hr/PeopleAccessService.php'));
    $people = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));
    $reporting = file_get_contents(app_path('Services/Hr/ReportingLineAdministrationService.php'));

    expect($access)->toContain("whereIn('line_type', ['primary', 'dotted_line'])")
        ->and($people)->toContain('projectAssignmentManagers($assignment,$actor)')
        ->toContain('closeForEmploymentEnd')
        ->and($reporting)->toContain('\'hr_partner\' => $assignment->hr_partner_staff_id,')
        ->toContain("'assignment-reporting:'")
        ->toContain("'reporting_line_cancelled'");
});

it('keeps change reasons behind reporting-line manage permission', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/ReportingLineController.php'));

    expect($controller)->toContain("can('hr.reporting-lines.manage') ? 'line.reason' : 'NULL AS reason'")
        ->toContain("where('staff.company_id', \$companyId)")
        ->toContain("from('hr_employment_spells')");
});

it('adds retained event history and refuses destructive rollback after use', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_14_109000_govern_hr_reporting_lines.php'));

    expect($migration)->toContain("Schema::create('hr_reporting_line_events'")
        ->toContain("['reporting_line_id', 'reporting_line_version']")
        ->toContain('Refusing to remove retained HR reporting-line history.')
        ->toContain("Schema::dropIfExists('hr_reporting_line_events')");
});

it('uses a bounded tenant-scoped Staff selector for both reporting-line references', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/ReportingLineController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $dialog = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/reporting-line-administration/reporting-line-create-dialog.component.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/reporting-line-administration/reporting-line-administration.component.ts'));

    expect($controller)
        ->toContain('public function staffSelectorOptions(Request $request): JsonResponse')
        ->toContain("'selected_id' => ['nullable', 'uuid']")
        ->toContain("'max:50'")
        ->toContain("where('staff.company_id', \$companyId)")
        ->and($routes)->toContain("reporting-line-staff-selector-options', [ReportingLineController::class, 'staffSelectorOptions'])->middleware('permission:hr.reporting-lines.manage')")
        ->and($dialog)->toContain('formControlName="member_staff_id" endpoint="/hr/organization/reporting-line-staff-selector-options"')
        ->toContain('formControlName="manager_staff_id" endpoint="/hr/organization/reporting-line-staff-selector-options"')
        ->not->toContain('data.options')
        ->and($component)->not->toContain('loadOptions()');
});
