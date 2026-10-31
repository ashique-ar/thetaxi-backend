<?php

it('locks attendance records without a company scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));

    expect($controller)->not->toContain('lockActiveCompany')
        ->not->toContain('company_id');
    foreach (['updateCalendar', 'storeCalendarDay', 'updateCalendarDay', 'updateShift', 'updatePolicy', 'approvePolicy', 'updateRoster', 'approveRoster', 'resolveException', 'transitionPeriod'] as $method) {
        preg_match('/    public function '.preg_quote($method, '/').'\b(.*?)(?=\n    (?:public|private) function |\n})/s', $controller, $match);
        expect($match[1] ?? '')->toContain('lockForUpdate(');
    }

    $service = file_get_contents(app_path('Services/Hr/Attendance/AttendanceResultService.php'));
    preg_match('/    public function calculate\b(.*?)(?=\n    public function |\n})/s', $service, $match);
    $body = $match[1] ?? '';
    $periodLock = strpos($body, "DB::table('hr_attendance_periods')");
    $staffLock = strpos($body, "DB::table('staff')");

    expect($service)->not->toContain("DB::table('companies')")
        ->and($periodLock)->not->toBeFalse()
        ->and($staffLock)->not->toBeFalse()
        ->and($periodLock)->toBeLessThan($staffLock);
});
