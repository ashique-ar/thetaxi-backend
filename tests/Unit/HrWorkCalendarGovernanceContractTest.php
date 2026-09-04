<?php

it('gives People Core its own work-calendar write path independent of the attendance_results feature flag', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect($controller)
        ->toContain('public function storeWorkCalendar(Request $request): JsonResponse')
        ->toContain('public function storeWorkCalendarDay(Request $request, string $calendarId): JsonResponse')
        ->not->toContain("hr.features.attendance_results");
});

it('does not modify the existing Attendance calendar write endpoint or its own feature gate', function () {
    $attendanceController = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));

    expect($attendanceController)
        ->toContain("public function storeCalendar(Request \$request): JsonResponse")
        ->toContain('private function writes(): void')
        ->toContain("config('hr.features.attendance_results', false)");
});

it('governs work-calendar and calendar-day creation as idempotent, replay-safe, audited commands', function () {
    $service = file_get_contents(app_path('Services/Hr/OrganizationAdministrationService.php'));

    expect($service)
        ->toContain('public function createWorkCalendar(array $data,string $companyId,string $actorUserId):array')
        ->toContain('public function createWorkCalendarDay(string $calendarId,array $data,string $companyId,string $actorUserId):array')
        ->toContain("if(\$replay=\$this->replay(\$data['idempotency_key'],\$checksum,\$companyId,'work_calendar'))return\$replay;")
        ->toContain("if(\$replay=\$this->replay(\$data['idempotency_key'],\$checksum,\$companyId,'work_calendar_day'))return\$replay;")
        ->toContain("abort_if(DB::table('hr_work_calendar_days')->where('calendar_id',\$calendarId)->whereDate('calendar_date',\$payload['calendar_date'])->exists(),409,");
});

it('exposes work calendars under the existing hr.organization permissions without minting new ones', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::get('work-calendars', [PeopleCoreController::class, 'workCalendars'])")
        ->toContain("Route::post('work-calendars', [PeopleCoreController::class, 'storeWorkCalendar'])")
        ->toContain("Route::post('work-calendars/{calendarId}/days', [PeopleCoreController::class, 'storeWorkCalendarDay'])")
        ->toContain("middleware('permission:hr.organization.view')")
        ->toContain("middleware('permission:hr.organization.manage')");
});
