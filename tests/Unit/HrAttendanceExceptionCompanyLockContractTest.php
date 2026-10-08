<?php

it('locks the active company before attendance records and keeps exception audit limited', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));
    foreach (['updateCalendar', 'storeCalendarDay', 'updateCalendarDay', 'updateShift', 'updatePolicy', 'approvePolicy', 'updateRoster', 'approveRoster', 'resolveException', 'transitionPeriod'] as $method) {
        preg_match('/    public function '.preg_quote($method, '/').'\\b(.*?)(?=\\n    (?:public|private) function |\\n})/s', $controller, $match);
        $body = $match[1] ?? '';
        $companyLock = strpos($body, '$this->lockActiveCompany(');
        $recordLock = strpos($body, 'lockForUpdate(');

        expect($companyLock)->not->toBeFalse();
        expect($recordLock)->not->toBeFalse();
        expect($companyLock)->toBeLessThan($recordLock);
    }

    preg_match('/    public function resolveException\\b(.*?)(?=\\n    (?:public|private) function |\\n})/s', $controller, $match);
    $body = $match[1] ?? '';
    expect($body)->toContain("'company_id' => \$row->company_id, 'status' => 'resolved', 'exception_type' => \$row->exception_type");
});

it('locks the active company before the attendance period during result calculation', function () {
    $service = file_get_contents(app_path('Services/Hr/Attendance/AttendanceResultService.php'));
    preg_match('/    public function calculate\\b(.*?)(?=\\n    public function |\\n})/s', $service, $match);
    $body = $match[1] ?? '';

    expect(strpos($body, "DB::table('companies')"))->not->toBeFalse();
    expect(strpos($body, "DB::table('hr_attendance_periods')"))->not->toBeFalse();
    expect(strpos($body, "DB::table('companies')"))->toBeLessThan(strpos($body, "DB::table('hr_attendance_periods')"));
});
