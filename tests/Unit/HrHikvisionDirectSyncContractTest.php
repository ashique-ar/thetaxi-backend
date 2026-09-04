<?php

it('wires scheduled incremental and lookback reconciliation with immutable deduplicated evidence', function () {
    $service = file_get_contents(app_path('Services/Hr/Attendance/DirectAttendanceSyncService.php'));
    $console = file_get_contents(base_path('routes/console.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_31_170000_support_direct_isapi_attendance_ingestion.php'));
    expect($service)->toContain(<<<'PHP'
where('device_id',$device->id)->where('provider_event_id',$event['provider_event_id'])
PHP)
        ->toContain(<<<'PHP'
'mapping_status'=>$reason?'quarantined':'mapped'
PHP)
        ->toContain("'person_missing'")->toContain("'person_unmapped'")->toContain("'person_mapping_ambiguous'")
        ->and($console)->toContain('hr:hikvision-sync --lookback-minutes=15')->toContain('hr:hikvision-sync --days=2')
        ->and($migration)->toContain('hr_attendance_device_provider_event_unique');
});

it('exposes permission-gated manual sync and run diagnostics in the admin UI', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/services/hr-attendance.service.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-devices/attendance-devices.component.html'));
    expect($routes)->toContain("Route::post('devices/{deviceId}/sync'")->toContain("Route::get('sync-runs'")
        ->and($controller)->toContain('manual_reconciliation')->toContain("'days' => ['nullable', 'integer', 'min:1', 'max:31']")
        ->and($service)->toContain('syncDevice(deviceId: string, days = 2)')->toContain("'/hr/attendance/sync-runs'")
        ->and($template)->toContain('sync(device.id)')->toContain('Recent synchronization');
});

it('aligns attendance eloquent models with inherited soft-delete and user-tracking behavior', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_31_171000_align_attendance_models_with_base_model.php'));
    expect($migration)->toContain("'hr_attendance_connectors','hr_attendance_devices','hr_attendance_raw_events'")
        ->toContain("foreignUuid('updated_user_id')")->toContain('softDeletes()');
});

it('provides reviewed device-person mapping and bulk quarantine reconciliation', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    expect($routes)->toContain('devices/{deviceId}/people')->toContain('mapping-candidates')
        ->and($controller)->toContain('public function devicePeople')->toContain('public function mappingCandidates')
        ->toContain("json_encode(array_values(array_unique(\$data['enrolled_methods'])), JSON_THROW_ON_ERROR)")
        ->toContain('Resolved automatically when the device-person mapping was saved.')
        ->toContain("'enrollment_status' => 'verified'")
        ->toContain("\$employeeNumberSnapshot = \$data['provider_person_id']")
        ->toContain("'effective_until' => \$data['effective_from']")
        ->not->toContain('The mapping creator cannot approve the same mapping.')
        ->toContain(<<<'PHP'
'resolved_quarantine_count' => $resolved
PHP);
});

it('provisions Staff users on a selected terminal entirely through the system portal', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/services/hr-attendance.service.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-people/attendance-people.component.html'));
    expect($routes)->toContain("Route::post('devices/{deviceId}/people'")
        ->and($routes)->toContain("Route::put('devices/{deviceId}/people/{employeeNumber}'")
        ->and($controller)->toContain('public function provisionDevicePerson')->toContain('provisionPerson($device, $staff->code, $name)')
        ->and($controller)->toContain('public function updateDevicePerson')->toContain('updatePerson($device, $employeeNumber, $name')
        ->and($service)->toContain('provisionDevicePerson(deviceId: string, staffId: string)')
        ->and($template)->toContain('Map to Staff')
        ->and($template)->toContain('Save reviewed mapping');
});

it('surfaces scheduler health and synchronizes mapped Staff lifecycle without Hikvision applications', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));
    $command = file_get_contents(app_path('Console/Commands/SyncHikvisionPeople.php'));
    $console = file_get_contents(base_path('routes/console.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-overview/attendance-overview.component.html')).file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-people/attendance-people.component.html'));
    expect($controller)->toContain("'automation'")->toContain("'healthy' => \$automationHealthy")
        ->and($command)->toContain('hr:hikvision-people-sync')->toContain('employment_ended_at')->toContain('setPersonEnabled(')->not->toContain('updatePerson(')
        ->and($console)->toContain('hr:hikvision-people-sync')->toContain('everyFifteenMinutes()')
        ->and($template)->toContain('Automatic synchronization')->toContain('Disable');
});

it('governs reviewed identity reconciliation and masked card and PIN lifecycle in the portal', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));
    $adapter = file_get_contents(app_path('Services/Hr/Attendance/HikvisionIsapiAdapter.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-people/attendance-people.component.html'));
    $migration = file_get_contents(database_path('migrations/2026_09_01_090000_add_hikvision_identity_and_credential_governance.php'));
    expect($routes)->toContain('people/bulk-mapping')->toContain('/credentials')->toContain('/cards')->toContain('/pin')->toContain('/disposition')
        ->and($controller)->toContain('safeIdentityMatch')->toContain('credentialFingerprint')->toContain('maskCard')->toContain('idempotency_key')
        ->and($adapter)->toContain('CardInfo/Search')->toContain('CardInfo/SetUp')->toContain('CardInfo/Delete')->toContain("'password' => \$pin")
        ->and($template)->toContain('Save reviewed mapping')->toContain('Cards')->toContain('PIN values are sent once')->toContain('Need mapping')
        ->and($migration)->toContain('hr_attendance_identity_dispositions')->toContain('hr_attendance_credential_events');
});

it('restricts reboot maintenance and supports audited terminal display-name editing', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/HikvisionManagementController.php'));
    $deviceController = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));
    $worker = file_get_contents(app_path('Console/Commands/ProcessHikvisionMaintenance.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-people/attendance-people.component.html')).file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-access/attendance-access.component.html'));
    expect($routes)->toContain('hr.attendance.maintenance.execute')->toContain('hr.attendance.maintenance.approve')
        ->and($controller)->toContain('The reboot requester cannot approve')->toContain('last_identity_probe_at')->toContain('maintenance.reboot')
        ->and($worker)->toContain('recovered_verified')->toContain('Recovery probe reached a different terminal identity')
        ->and($deviceController)->toContain('terminal_person_update')->toContain('post-write reconciliation did not match')
        ->and($template)->toContain('Terminal display name')->toContain('Request reboot approval')->toContain('Approve as second person');
});

it('delivers maker-checker access and governs clock settings rotation alerts and dead letters', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $console = file_get_contents(base_path('routes/console.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/HikvisionManagementController.php'));
    $delivery = file_get_contents(app_path('Console/Commands/ProcessHikvisionAccessCommands.php'));
    $monitor = file_get_contents(app_path('Console/Commands/MonitorHikvisionDevices.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-access/attendance-access.component.html'));
    expect($routes)->toContain('access-groups')->toContain('time-configuration')->toContain('safe-settings')->toContain('rotate-credentials')->toContain('device-alerts')
        ->and($console)->toContain('hr:hikvision-access-deliver')->toContain('hr:hikvision-monitor')
        ->and($controller)->toContain('New credentials failed after save')->toContain('previous encrypted configuration was restored')->toContain('safe_snapshot')
        ->and($delivery)->toContain('approved_pending_delivery')->toContain('dead_letter')->toContain('delivered_reconciled')->toContain('applyAccess')
        ->and($monitor)->toContain('clock_drift')->toContain('stale_sync')->toContain('hikvision_device_alert')
        ->and($template)->toContain('Authorization and terminal controls')->toContain('Submit for approval')->toContain('Rotate and verify')->toContain('Alerts');
});

it('normalizes identified access events as punches consumed by attendance calculation', function () {
    $adapter = file_get_contents(app_path('Services/Hr/Attendance/HikvisionIsapiAdapter.php'));
    $calculator = file_get_contents(app_path('Services/Hr/Attendance/AttendanceResultService.php'));
    expect($adapter)->toContain("'event_kind' => \$personId !== '' ? 'punch' : 'access_control'")
        ->and($calculator)->toContain("whereIn('raw.event_kind', ['punch', 'access_control'])");
});

it('makes the complete portal workflow discoverable and exposes Staff prerequisite repair links', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));
    $navigation = file_get_contents(base_path('../portal-thetaxi/src/app/core/navigation/sections/navigation.people.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-people/attendance-people.component.html'));
    expect($routes)->toContain('legacy-staff-gaps')->toContain('permission:staff.edit-all')
        ->and($controller)->toContain('public function legacyStaffGaps')->toContain("whereNull('staff.company_id')->orWhereNull('staff.code')")
        ->and($navigation)->toContain("title: 'Attendance Operations'")->toContain("'hr.attendance.devices.view'")->toContain("'hr.attendance.results.view'")
        ->and($template)->toContain('Terminal people')->toContain('Map to Staff')->toContain('Staff member')->toContain('Save reviewed mapping')
        ->toContain('Search name or employee number')->toContain('Attendance terminal')->toContain('Terminal display name');
});
