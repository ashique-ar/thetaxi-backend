<?php

it('adds the missing person-mapping list endpoint so quarantine resolution can select a verified mapping', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php'));

    expect($controller)
        ->toContain('public function mappings(Request $request): JsonResponse')
        ->toContain("'status' => ['nullable', Rule::in(['pending', 'verified'])]")
        ->toContain("->where('company_id', \$companyId)");
});

it('exposes the mapping list under the existing devices-view permission rather than minting a new one', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('person-mappings', [AttendanceDeviceController::class,'mappings'])->middleware('permission:hr.attendance.devices.view');");
});

it('wires the mapping list into the Angular attendance service and gates the resolve action on the existing quarantine.resolve permission', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/services/hr-attendance.service.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-operations/attendance-operations.component.ts'));

    expect($service)->toContain("personMappings(params: any = {}) { return this.makeGetCall('/hr/attendance/person-mappings', params); }");
    expect($component)
        ->toContain("this.auth.hasPermission('hr.attendance.quarantine.resolve').subscribe(ok=>this.canResolveQuarantine.set(ok));")
        ->toContain("matchingMappings(providerPersonId:string){return this.mappings().filter(m=>m.provider_person_id===providerPersonId);}");
});
