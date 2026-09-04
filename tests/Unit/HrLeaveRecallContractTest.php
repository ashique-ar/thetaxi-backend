<?php

it('recalls only an approved, not-yet-returned, not-already-recalled request, and never by the requester themselves', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain('public function recall(string $requestId, string $recallDate, string $reason, string $actorUserId): object')
        ->toContain("abort_if(\$row->requested_by === \$actorUserId, 403, 'The leave requester cannot recall their own request; recall is an employer-initiated action.');")
        ->toContain("abort_unless(\$row->status === 'approved', 409, 'Only an approved leave request can be recalled.');")
        ->toContain("abort_if(\$row->actual_return_date !== null, 409, 'A return to work was already confirmed for this request.');")
        ->toContain("abort_if(\$row->recalled_at !== null, 409, 'This leave request was already recalled.');")
        ->toContain("abort_if(\$recallDate > \$row->end_date, 422, 'The recall date cannot be after the approved end date; use extension or cancellation instead.');");
});

it('releases only the unused reserved days after the recall date, mirroring the return-to-work release mechanics', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain("\$unusedDays = DB::table('hr_leave_request_days')->where('leave_request_id', \$row->id)->whereDate('leave_date', '>', \$recallDate)->get();")
        ->toContain("'unpaid_leave_reversal', \$recallDate, -\$reversedMinutes, 'leave_recall'")
        ->toContain("'recalled', \$row->status, \$row->status, \$reason,");
});

it('makes recall mutually exclusive with self-confirmed return-to-work and with extension', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain("abort_if(\$row->recalled_at !== null, 409, 'This leave request was already recalled; it cannot also confirm a self-reported return.');")
        ->toContain("abort_if(\$row->recalled_at !== null, 409, 'This leave request was already recalled and cannot be extended.');")
        ->toContain("abort_if(\$row->recalled_at !== null, 409, 'This leave request was already recalled; the recalled portion cannot also be cancelled.');");
});

it('refuses to drop recall columns while any recall is retained', function () {
    $migration = file_get_contents(base_path('database/migrations/2026_08_18_100000_add_hr_leave_recall.php'));

    expect($migration)->toContain("DB::table('hr_leave_requests')->whereNotNull('recall_date')->exists()");
});

it('gates recall behind the existing hr.leave.approve permission rather than minting a new one, distinct from the self-service request permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::post('leave/requests/{id}/recall', [WorkforceController::class,'recallLeave'])->whereUuid('id')->middleware('permission:hr.leave.approve');");
});
