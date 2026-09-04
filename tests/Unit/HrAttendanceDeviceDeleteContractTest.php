<?php

it('soft deletes attendance terminals from the portal while retaining evidence', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/Concerns/ManagesAttendanceDeviceCrud.php'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/services/hr-attendance.service.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-devices/attendance-devices.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-devices/attendance-devices.component.html'));

    expect($routes)->toContain("Route::delete('devices/{deviceId}'")
        ->toContain("middleware('permission:hr.attendance.devices.manage')")
        ->and($controller)->toContain('public function destroyDevice')
        ->toContain('$this->authorizedCompanyId($request, $device->company_id)')
        ->toContain('$device->delete()')
        ->not->toContain('$device->forceDelete()')
        ->and($service)->toContain('deleteDevice(deviceId: string)')
        ->and($component)->toContain('Historical attendance and audit evidence will be retained.')
        ->and($template)->toContain('Remove terminal');
});

it('discovers direct ISAPI identity during registration instead of trusting a typed serial', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/Concerns/ManagesAttendanceDeviceCrud.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-devices/attendance-devices.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-devices/attendance-devices.component.html'));

    expect($controller)->toContain('storeDevice(Request $request, AttendanceProviderManager $providers)')
        ->toContain('$facts = $adapter->discover($candidate)')
        ->toContain('$data[\'serial_number\'] = $facts[\'serial_number\']')
        ->and($component)->not->toContain("serial_number: ['', Validators.required]")
        ->and($template)->not->toContain('formControlName="serial_number"');
});

it('loads the validation rule dependency used by device people mapping', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/Concerns/ManagesDevicePeopleMapping.php'));

    expect($controller)->toContain('use Illuminate\\Validation\\Rule;')
        ->toContain("Rule::in(['fingerprint', 'card', 'face', 'pin'])");
});
