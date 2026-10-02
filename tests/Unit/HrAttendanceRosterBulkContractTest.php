<?php

it('adds a bulk roster-assignment endpoint that reuses storeRoster\'s exact per-staff legal-entity and overlap checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));

    expect($controller)
        ->toContain('public function storeRosterBulk(Request $request): JsonResponse')
        ->toContain("'staff_ids'=>['required','array','min:1','max:200'],'staff_ids.*'=>['required','uuid','distinct']")
        ->toContain("if(!DB::table('staff')->where('id',\$staffId)->where('company_id',\$data['company_id'])->exists()){\$skipped[]=['staff_id'=>\$staffId,'reason'=>'Staff does not belong to this legal entity.'];continue;}")
        ->toContain("if(\$overlap){\$skipped[]=['staff_id'=>\$staffId,'reason'=>'An overlapping roster already exists.'];continue;}")
        ->toContain("return response()->json(['status'=>'success','data'=>['created'=>\$created,'skipped'=>\$skipped]],201);");
});

it('registers the bulk roster route under the same manage permission as the single-staff roster route', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::post('rosters/bulk', [AttendanceResultController::class,'storeRosterBulk'])->middleware('permission:hr.attendance.config.manage');");
});

it('wires the bulk roster form into the existing Angular attendance configuration page', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/services/hr-attendance.service.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-configuration/attendance-configuration.component.ts'));

    expect($service)->toContain("storeRosterBulk(payload: any) { return this.makePostCall('/hr/attendance/rosters/bulk', payload); }");
    expect($component)
        ->toContain('staff_ids: [[] as string[], Validators.required]')
        ->toContain('submitRosterBulk()')
        ->toContain('this.api.storeRosterBulk(this.clean(v))');

    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-configuration/attendance-configuration.component.html'));
    expect($template)->toContain('formControlName="staff_ids"')->toContain('[multiple]="true"')->toContain('[alwaysShowSearch]="true"');
});

it('serializes roster edits with create and bulk overlap checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));
    $update = substr($controller, strpos($controller, 'public function updateRoster('));
    $update = substr($update, 0, strpos($update, 'public function storeRosterBulk('));

    expect($update)
        ->toContain("DB::table('companies')->where('id', \$row->company_id)->lockForUpdate()->first();")
        ->toContain("->where('id', '!=', \$rosterId)")
        ->toContain("abort_if(\$overlap, 409, 'An overlapping roster already exists.')");
    expect(strpos($update, 'lockForUpdate'))->toBeLessThan(strpos($update, '$overlap ='));
});
