<?php

it('adds the missing correction-queue list endpoint, scoped like the existing exceptions list', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));

    expect($controller)
        ->toContain('public function corrections(Request $request,StaffAccessService $access): JsonResponse')
        ->toContain("\$staffIds=\$access->scope(Staff::query(),\$request->user())->when(\$data['staff_id']??null,fn(\$q,\$id)=>\$q->whereKey(\$id))->select('id');\n        \$query=DB::table('hr_attendance_correction_requests')->whereIn('staff_id',\$staffIds)")
        ->toContain("'status'=>['nullable',Rule::in(['pending_approval','approved','rejected'])]");
});

it('exposes the correction queue under the existing read permission, distinct from the request/approve permissions', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('corrections', [AttendanceResultController::class,'corrections'])->middleware('permission:hr.attendance.results.view');");
});

it('wires the correction queue and both decisions into the Angular attendance service, which previously had no caller for either', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/services/hr-attendance.service.ts'));

    expect($service)
        ->toContain("corrections(params: any = {}) { return this.makeGetCall('/hr/attendance/corrections', params); }")
        ->toContain("approveCorrection(id: string, payload: any) { return this.makePostCall(`/hr/attendance/corrections/\${id}/approve`, payload); }")
        ->toContain("rejectCorrection(id: string, payload: any) { return this.makePostCall(`/hr/attendance/corrections/\${id}/reject`, payload); }");
});

it('gates the Angular decide/resolve buttons to a non-requester holding the approve/resolve permission and an open/pending row', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-results/attendance-results.component.ts'));

    expect($component)
        ->toContain("canDecideCorrection(row:any){return this.canApproveCorrections()&&row.requested_by!==this.myUserId&&row.status==='pending_approval';}")
        ->toContain("canResolveException(row:any){return this.canResolveExceptions()&&row.status==='open';}");
});
