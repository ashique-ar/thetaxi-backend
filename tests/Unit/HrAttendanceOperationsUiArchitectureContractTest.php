<?php

it('splits attendance operations into independently owned route components', function () {
    $root = base_path('../portal-thetaxi/src/app/modules/hr-attendance');
    $routes = file_get_contents($root.'/hr-attendance.routes.ts');
    $features = [
        'attendance-operations-shell',
        'attendance-overview',
        'attendance-people',
        'attendance-devices',
        'attendance-access',
        'attendance-exceptions',
    ];

    foreach ($features as $feature) {
        $directory = $root.'/components/'.$feature;
        expect(is_file($directory.'/'.$feature.'.component.ts'))->toBeTrue()
            ->and(is_file($directory.'/'.$feature.'.component.html'))->toBeTrue()
            ->and(is_file($directory.'/'.$feature.'.component.scss'))->toBeTrue();
    }

    expect($routes)->toContain("path: 'overview'")
        ->toContain("path: 'people'")
        ->toContain("path: 'devices'")
        ->toContain("path: 'access'")
        ->toContain("path: 'exceptions'")
        ->not->toContain("path: 'legacy'");
});

it('keeps high-risk and daily Hikvision workflows in their focused workspaces', function () {
    $root = base_path('../portal-thetaxi/src/app/modules/hr-attendance/components');
    $people = file_get_contents($root.'/attendance-people/attendance-people.component.html');
    $access = file_get_contents($root.'/attendance-access/attendance-access.component.html');
    $devices = file_get_contents($root.'/attendance-devices/attendance-devices.component.html');
    $exceptions = file_get_contents($root.'/attendance-exceptions/attendance-exceptions.component.html');

    expect($people)->toContain('Terminal display name')->toContain('Assign card')->toContain('Set PIN')->toContain('Save reviewed mapping')
        ->toContain('Change mapping')->toContain('Reason for correction')
        ->and($devices)->toContain('Test identity')->toContain('sync(device.id)')->toContain('Register terminal')
        ->and($access)->toContain('Submit for approval')->toContain('Rotate and verify')->toContain('Request reboot approval')
        ->and($exceptions)->toContain('Resolve and reconcile');
});

it('never labels an existing Staff mapping as needing mapping when the Staff code is blank', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-people/attendance-people.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-people/attendance-people.component.html'));

    expect($component)->toMatch('/if\s*\(!person\.mapping\)\s*\{\s*const suggestion = this\.suggestedStaff\(person\)/')
        ->toContain("'Mapped to Staff'")
        ->and($template)->toContain('mappingLabel(p)')
        ->not->toContain("p.mapping?.staff_code||'Needs mapping'");
});

it('shows and preselects a unique exact Staff candidate without silently creating a mapping', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-people/attendance-people.component.ts'));

    expect($component)->toMatch('/matches\.length === 1 \? matches\[0\] : null/')
        ->toContain('— confirm mapping')
        ->toMatch("/this\.suggestedStaff\(person\)\?\.id\s*\|\|\s*''/")
        ->toContain("'Confirm match'");
});

it('provides real edit workflows for every attendance configuration owner', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));
    $routes=file_get_contents(base_path('routes/api.php'));
    $component=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-configuration/attendance-configuration.component.ts'));
    $template=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-configuration/attendance-configuration.component.html'));
    foreach(['updateCalendar','updateCalendarDay','updateShift','updatePolicy','updateRoster'] as $method){
        expect($controller)->toContain("function {$method}")
            ->and($component)->toContain($method);
    }
    expect($routes)->toContain("Route::put('calendars/{calendarId}'")
        ->toContain("Route::put('shifts/{shiftId}'")
        ->toContain("Route::put('policies/{policyId}'")
        ->toContain("Route::put('rosters/{rosterId}'")
        ->and($template)->toContain('Save calendar changes')->toContain('Save shift changes')->toContain('Save policy changes')->toContain('Save roster changes');
});

it('uses the shared UI ownership layer throughout every attendance screen', function () {
    $root=base_path('../portal-thetaxi/src/app/modules/hr-attendance/components');
    foreach(glob($root.'/*/*.component.html') as $templatePath){
        $template=file_get_contents($templatePath);
        expect($template,basename(dirname($templatePath)).' must use shared UI components')->toContain('<app-ui-')
            ->not->toContain('class="error"')
            ->not->toContain('<header>');
    }
    $configuration=file_get_contents($root.'/attendance-configuration/attendance-configuration.component.html');
    foreach(['app-ui-stat-card','app-ui-section-panel','app-ui-data-table-shell','app-ui-form-section','app-ui-action-bar','app-ui-status-badge'] as $component){
        expect($configuration)->toContain($component);
    }
});

it('uses addressable routes for every attendance configuration workspace', function () {
    $routes=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/hr-attendance.routes.ts'));
    $component=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-configuration/attendance-configuration.component.ts'));
    $template=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-configuration/attendance-configuration.component.html'));

    expect($routes)->toContain("redirectTo: 'configuration/calendars'")
        ->toContain("['calendars', 'shifts', 'policies', 'rosters']")
        ->toContain('attendanceConfigurationSection')
        ->and($component)->toContain("this.router.navigate(['/hr/attendance/configuration', section])")
        ->toContain("this.route.data.subscribe")
        ->and($template)->toContain('(change)="selectSection($event.value)"');
});

it('renders only the selected attendance configuration page', function () {
    $template=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-configuration/attendance-configuration.component.html'));
    expect($template)->not->toContain('[hidden]="activeSection()')
        ->and(substr_count($template, '*ngIf="activeSection() ==='))->toBe(4);
});

it('recalculates attendance when effective shift values change and supersedes stale exceptions', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));
    $service=file_get_contents(app_path('Services/Hr/Attendance/AttendanceResultService.php'));

    expect($controller)->toContain('recalculated_result_count')
        ->toContain("where('shift_id', \$shiftId)")
        ->toContain("\$results->calculate(")
        ->and($service)->toContain("'shift' => (array) \$shift")
        ->toContain("'status' => 'superseded'")
        ->toContain('Superseded by recalculated attendance result');
});
