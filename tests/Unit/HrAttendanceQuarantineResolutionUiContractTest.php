<?php

it('provides an item-scoped eligible mapping selector for quarantine resolution', function () {
    $controller = hr_attendance_device_controller_source();

    expect($controller)
        ->toContain('public function quarantineMappingOptions(Request $request, string $itemId): JsonResponse')
        ->toContain("->where('mapping.enrollment_status', 'verified')")
        ->toContain("->where('mapping.provider_person_id', \$event->provider_person_id)")
        ->toContain("->lockForUpdate()->first()");
});

it('exposes item-scoped options under the existing quarantine-resolution permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('quarantine/{itemId}/mapping-options', [AttendanceDeviceController::class, 'quarantineMappingOptions'])->whereUuid('itemId')->middleware('permission:hr.attendance.quarantine.resolve');");
});

it('uses the shared managed-record selector and gates resolution on the existing permission', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-exceptions/attendance-exceptions.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-exceptions/attendance-exceptions.component.html'));

    expect($component)
        ->toContain("this.auth.hasPermission('hr.attendance.quarantine.resolve')")
        ->toContain('if (!this.canResolve()');
    expect($template)
        ->toContain('<app-ui-managed-record-select')
        ->toContain("'/hr/attendance/quarantine/' + row.id + '/mapping-options'");
});
