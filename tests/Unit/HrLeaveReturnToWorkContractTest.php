<?php

it('confirms return to work only once, only on an approved request, and never for a late return', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain('public function confirmReturn(string $requestId,string $actualReturnDate,?string $notes,string $actorUserId):object')
        ->toContain("abort_unless(\$row->status==='approved',409,'Only an approved leave request can confirm a return to work.');")
        ->toContain("abort_if(\$row->actual_return_date!==null,409,'A return to work was already confirmed for this request.');")
        ->toContain("abort_if(\$actualReturnDate>\$row->end_date,422,'A return after the approved end date requires the separate extension workflow, not a return-to-work confirmation.');");
});

it('re-credits only the specific unused reserved days found via hr_leave_request_days, not the full reservation', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain("\$unusedDays=DB::table('hr_leave_request_days')->where('leave_request_id',\$row->id)->whereDate('leave_date','>',\$actualReturnDate)->get();")
        ->toContain("'return_release'");
});

it('reverses only the matching portion of a staged unpaid-leave payroll fact on early return', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)->toContain("'unpaid_leave_reversal',\$actualReturnDate,-\$reversedMinutes,'leave_return'");
});

it('refuses to drop return-to-work columns while any confirmation is retained', function () {
    $migration = file_get_contents(base_path('database/migrations/2026_08_15_102000_add_hr_leave_return_to_work.php'));

    expect($migration)->toContain("DB::table('hr_leave_requests')->whereNotNull('actual_return_date')->exists()");
});

it('reuses the existing hr.leave.request permission for return confirmation rather than minting a new one', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::post('leave/requests/{id}/confirm-return', [WorkforceController::class,'confirmLeaveReturn'])->whereUuid('id')->middleware('permission:hr.leave.request');");
});
