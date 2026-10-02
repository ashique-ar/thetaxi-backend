<?php

it('uses the tenant-scoped searchable terminal selector in attendance mapping and access screens', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/Concerns/ManagesAttendanceDeviceCrud.php'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/services/hr-attendance.service.ts'));
    $people = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-people/attendance-people.component.html'));
    $access = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-access/attendance-access.component.html'));
    $peopleComponent = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-people/attendance-people.component.ts'));
    $accessComponent = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-access/attendance-access.component.ts'));

    expect($routes)->toContain("Route::get('device-options', [AttendanceDeviceController::class, 'deviceOptions'])")
        ->toContain("permission:hr.attendance.devices.view")
        ->and($controller)->toContain('public function deviceOptions(Request $request)')
        ->toContain('$this->authorizedCompanyId($request, null)')
        ->toContain("'selected_id' => ['nullable', 'uuid']")
        ->toContain("'page' => ['nullable', 'integer', 'min:1']")
        ->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("'value' => $device->id")
        ->toContain("'company_id' => $device->company_id")
        ->and($service)->toContain("makeGetCall('/hr/attendance/device-options', params)")
        ->and(substr_count($people, 'endpoint="/hr/attendance/device-options"'))->toBe(1)
        ->and(substr_count($access, 'endpoint="/hr/attendance/device-options"'))->toBe(1)
        ->and($peopleComponent)->not->toContain('this.api.health()')
        ->toContain('selectionRevision')
        ->and($accessComponent)->not->toContain('this.api.health()')
        ->toContain('selectionRevision')
        ->and($people)->not->toContain('<mat-select')
        ->and($access)->not->toContain('[value]="device()?.id"');
});
