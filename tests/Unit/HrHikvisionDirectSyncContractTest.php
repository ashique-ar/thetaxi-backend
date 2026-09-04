<?php

it('wires scheduled incremental and lookback reconciliation with immutable deduplicated evidence', function () {
    $service=file_get_contents(app_path('Services/Hr/Attendance/DirectAttendanceSyncService.php'));
    $console=file_get_contents(base_path('routes/console.php'));
    $migration=file_get_contents(database_path('migrations/2026_08_31_170000_support_direct_isapi_attendance_ingestion.php'));
    expect($service)->toContain(<<<'PHP'
where('device_id',$device->id)->where('provider_event_id',$event['provider_event_id'])
PHP)
        ->toContain(<<<'PHP'
'mapping_status'=>$reason?'quarantined':'mapped'
PHP)
        ->toContain("'person_missing'")->toContain("'person_unmapped'")->toContain("'person_mapping_ambiguous'")
        ->and($console)->toContain("hr:hikvision-sync --lookback-minutes=15")->toContain("hr:hikvision-sync --days=2")
        ->and($migration)->toContain("hr_attendance_device_provider_event_unique");
});

it('exposes permission-gated manual sync and run diagnostics in the admin UI', function () {
    $routes=file_get_contents(base_path('routes/api.php'));$controller=file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));
    $service=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/services/hr-attendance.service.ts'));
    $template=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-operations/attendance-operations.component.html'));
    expect($routes)->toContain("Route::post('devices/{deviceId}/sync'")->toContain("Route::get('sync-runs'")
        ->and($controller)->toContain('manual_reconciliation')->toContain("'days'=>['nullable','integer','min:1','max:31']")
        ->and($service)->toContain('syncDevice(deviceId: string, days = 2)')->toContain("'/hr/attendance/sync-runs'")
        ->and($template)->toContain('Sync 2 days')->toContain('Recent synchronization runs');
});

it('aligns attendance eloquent models with inherited soft-delete and user-tracking behavior', function () {
    $migration=file_get_contents(database_path('migrations/2026_08_31_171000_align_attendance_models_with_base_model.php'));
    expect($migration)->toContain("'hr_attendance_connectors','hr_attendance_devices','hr_attendance_raw_events'")
        ->toContain("foreignUuid('updated_user_id')")->toContain('softDeletes()');
});

it('provides reviewed device-person mapping and bulk quarantine reconciliation', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));$routes=file_get_contents(base_path('routes/api.php'));
    expect($routes)->toContain("devices/{deviceId}/people")->toContain("mapping-candidates")
        ->and($controller)->toContain('public function devicePeople')->toContain('public function mappingCandidates')
        ->toContain("json_encode(array_values(array_unique(\$data['enrolled_methods'])), JSON_THROW_ON_ERROR)")
        ->toContain('Resolved automatically when the device-person mapping was saved.')
        ->toContain("'enrollment_status'=>'verified'")
        ->toContain("\$employeeNumberSnapshot = \$data['provider_person_id']")
        ->toContain("'effective_until' => \$data['effective_from']")
        ->not->toContain('The mapping creator cannot approve the same mapping.')
        ->toContain(<<<'PHP'
'resolved_quarantine_count'=>$resolved
PHP);
});

it('provisions Staff users on a selected terminal entirely through the system portal', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));$routes=file_get_contents(base_path('routes/api.php'));
    $service=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/services/hr-attendance.service.ts'));
    $template=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-operations/attendance-operations.component.html'));
    expect($routes)->toContain("Route::post('devices/{deviceId}/people'")
        ->and($controller)->toContain('public function provisionDevicePerson')->toContain('provisionPerson($device, $staff->code, $name)')
        ->and($service)->toContain('provisionDevicePerson(deviceId: string, staffId: string)')
        ->and($template)->toContain('Create on device');
});

it('normalizes identified access events as punches consumed by attendance calculation', function () {
    $adapter=file_get_contents(app_path('Services/Hr/Attendance/HikvisionIsapiAdapter.php'));$calculator=file_get_contents(app_path('Services/Hr/Attendance/AttendanceResultService.php'));
    expect($adapter)->toContain("'event_kind' => \$personId !== '' ? 'punch' : 'access_control'")
        ->and($calculator)->toContain("whereIn('raw.event_kind', ['punch','access_control'])");
});

it('makes the complete portal workflow discoverable and exposes Staff prerequisite repair links', function () {
    $routes=file_get_contents(base_path('routes/api.php'));$controller=file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));
    $navigation=file_get_contents(base_path('../portal-thetaxi/src/app/core/navigation/sections/navigation.people.ts'));
    $template=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-operations/attendance-operations.component.html'));
    expect($routes)->toContain("legacy-staff-gaps")->toContain("permission:staff.edit-all")
        ->and($controller)->toContain('public function legacyStaffGaps')->toContain("whereNull('staff.company_id')->orWhereNull('staff.code')")
        ->and($navigation)->toContain("title: 'Hikvision Devices & Mapping'")->toContain("'hr.attendance.devices.view', 'hr.attendance.results.view'")
        ->and($template)->toContain('Map Staff')->toContain('Staff setup')->toContain('readinessTotal()')->toContain('Staff records blocking Hikvision mapping')->toContain("'/staff'")->toContain('staff.id')->toContain("'edit'")
        ->toContain('Search Hikvision users')->toContain('Hikvision device user')->toContain('Staff member')->toContain('Save or replace mapping');
});
