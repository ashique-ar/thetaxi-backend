<?php

it('adds the missing reject outcome for a pending attendance correction request', function () {
    $service = file_get_contents(app_path('Services/Hr/Attendance/AttendanceResultService.php'));

    expect($service)
        ->toContain('public function rejectCorrection(string $requestId, string $actorUserId, string $decisionNote): object')
        ->toContain("abort_if(\$correction->requested_by === \$actorUserId, 409, 'The correction requester cannot decide the same correction.');")
        ->toContain("abort_unless(\$correction->status === 'pending_approval', 409, 'Only pending corrections may be rejected.');")
        ->toContain("'status' => 'rejected'");
});

it('never blocks a rejection on a locked attendance period, unlike approval which changes payable facts', function () {
    $service = file_get_contents(app_path('Services/Hr/Attendance/AttendanceResultService.php'));
    $rejectMethod = substr($service, strpos($service, 'function rejectCorrection'));
    $rejectMethod = substr($rejectMethod, 0, strpos($rejectMethod, 'private function exceptions'));

    expect($rejectMethod)->not->toContain('hr_attendance_periods');
});

it('reuses the existing hr.attendance.corrections.approve permission for the reject route rather than minting a new one', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::post('corrections/{correctionId}/reject', [AttendanceResultController::class,'rejectCorrection'])->whereUuid('correctionId')->middleware('permission:hr.attendance.corrections.approve');");
});
