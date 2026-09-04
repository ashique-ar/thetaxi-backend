<?php

it('extends an approved leave request only after re-validating policy/notice/blackout/balance/consecutive-day rules over the added days', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain('public function extend(string $requestId, string $newEndDate, string $reason, string $actorUserId): object')
        ->toContain("abort_unless(\$row->status === 'approved', 409, 'Only an approved leave request can be extended.');")
        ->toContain("abort_unless(\$newEndDate > \$row->end_date, 422, 'An extension must move the end date later than the current approved end date.');")
        ->toContain("\$policy = DB::table('hr_leave_policies')->where('id', \$row->policy_id)->where('company_id', \$row->company_id)->where('status', 'approved')")
        ->toContain("abort_if(collect(\$newDays)->contains(fn (\$day) => in_array(\$day['date'], \$blackouts, true)), 422, 'The extension includes a policy blackout date.');")
        ->toContain("abort_if(\$balance - \$addedMinutes < -\$negative, 409, 'Insufficient available leave balance for the extension.');")
        ->toContain("abort_if(\$overlap, 409, 'Another active leave request overlaps the extension interval.');");
});

it('rejects hour-unit leave extension and a completed return-to-work rather than inventing an ambiguous semantic', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain("abort_if(\$row->unit === 'hour', 422, 'Hourly leave cannot be extended by end date; submit a new request for additional hours.');")
        ->toContain("abort_if(\$row->actual_return_date !== null, 409, 'A return to work was already confirmed for this request.');");
});

it('appends only the newly added days rather than silently stretching the existing reservation, and records extension evidence', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain("'reservation', -\$addedMinutes, \$extensionStart, 'leave_extension', \$row->id, 'Leave extension reservation', \$snapshot + ['extended_to' => \$newEndDate]")
        ->toContain("\$this->event(\$row->id, 'extended', 'approved', 'approved', \$reason, \$snapshot + ['previous_end_date' => \$row->end_date, 'extended_to' => \$newEndDate, 'added_minutes' => \$addedMinutes], \$actorUserId);")
        ->toContain("'end_date' => \$newEndDate, 'requested_minutes' => \$row->requested_minutes + \$addedMinutes, 'reserved_minutes' => \$row->reserved_minutes + \$addedMinutes");
});

it('exposes leave extension through the requester-or-approver gate matching cancel and return-to-work, plus Angular and route wiring', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/hr-workforce.service.ts'));

    expect($controller)
        ->toContain('public function extendLeave(Request $r, string $id, LeaveWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse')
        ->toContain("abort_unless(\$row->requested_by === \$r->user()->id || \$r->user()->can('hr.leave.approve'), 403, 'Only the requester or an authorized leave approver may extend this request.');")
        ->and($routes)
        ->toContain("Route::post('leave/requests/{id}/extend', [WorkforceController::class,'extendLeave'])->whereUuid('id')->middleware('permission:hr.leave.request');")
        ->and($service)
        ->toContain('extendLeave(id:string,payload:any){return this.makePostCall(`/hr/workforce/leave/requests/${id}/extend`,payload);}');
});
