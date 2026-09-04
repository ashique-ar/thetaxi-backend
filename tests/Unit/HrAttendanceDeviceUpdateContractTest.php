<?php

it('adds a device update endpoint that never overwrites the stored ISAPI password with a redacted value', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));

    expect($controller)
        ->toContain('public function updateDevice(Request $request, string $deviceId): JsonResponse')
        ->toContain("if (array_key_exists('encrypted_configuration', \$data)) {")
        ->toContain("\$data['encrypted_configuration'] = array_filter((array) \$device->encrypted_configuration, fn (\$v, \$k) => ! array_key_exists(\$k, \$data['encrypted_configuration']), ARRAY_FILTER_USE_BOTH) + \$data['encrypted_configuration'];");
});

it('redacts the device password from the health endpoint, only ever exposing ip_address/port/username', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));
    $health = substr($controller, strpos($controller, 'function health('));
    $health = substr($health, 0, strpos($health, 'public function storeConnector('));

    expect($health)
        ->toContain("->only(['ip_address', 'port', 'username'])")
        ->toContain("->makeHidden('encrypted_configuration')")
        ->not->toContain("'password'");
});

it('keeps device identity fields (provider, integration_mode, serial_number) immutable on update', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));
    $update = substr($controller, strpos($controller, 'function updateDevice('));
    $update = substr($update, 0, strpos($update, 'public function storeMapping('));

    expect($update)
        ->not->toContain("'provider'")
        ->not->toContain("'integration_mode'")
        ->not->toContain("'serial_number'");
});

it('registers the device update route under the existing hr.attendance.devices.manage permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::put('devices/{deviceId}', [AttendanceDeviceController::class,'updateDevice'])->whereUuid('deviceId')->middleware('permission:hr.attendance.devices.manage');");
});

it('wires device create/edit and connector create into the previously entirely read-only Angular operations page', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/services/hr-attendance.service.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-operations.component.ts'));

    expect($service)
        ->toContain("storeDevice(payload: any) { return this.makePostCall('/hr/attendance/devices', payload); }")
        ->toContain("updateDevice(deviceId: string, payload: any) { return this.makePutCall(`/hr/attendance/devices/\${deviceId}`, payload); }");
    expect($component)
        ->toContain("startEditDevice(row:any){this.editingDevice.set(row);const config=row.connection||{};")
        ->toContain("if(password)configuration['password']=password;")
        ->toContain("direct_isapi");
});
