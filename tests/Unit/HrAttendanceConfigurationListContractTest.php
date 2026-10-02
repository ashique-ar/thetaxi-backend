<?php

it('adds the missing calendar, shift, policy, roster, and period list endpoints, scoped to the actor legal entity', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));

    expect($controller)
        ->toContain('public function calendars(Request $request): JsonResponse')
        ->toContain('public function calendarDays(Request $request,string $calendarId): JsonResponse')
        ->toContain('public function shifts(Request $request): JsonResponse')
        ->toContain('public function policies(Request $request): JsonResponse')
        ->toContain('public function rosters(Request $request,StaffAccessService $access): JsonResponse')
        ->toContain('public function periods(Request $request): JsonResponse')
        ->toContain('private function actorCompany(Request $request,?string $requestedCompanyId): string{');
});

it('scopes rosters through the same self/team/all StaffAccessService as corrections and exceptions, not company-only', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));
    $rosters = substr($controller, strpos($controller, 'function rosters('));
    $rosters = substr($rosters, 0, strpos($rosters, 'public function storeRoster'));

    expect($rosters)->toContain("\$staffIds=\$access->scope(Staff::query(),\$request->user())");
});

it('gates config lists to either the manage or approve permission so an approve-only checker can still see pending items', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::get('calendars', [AttendanceResultController::class,'calendars'])->middleware('permission:hr.attendance.config.manage|hr.attendance.config.approve');")
        ->toContain("Route::get('policies', [AttendanceResultController::class,'policies'])->middleware('permission:hr.attendance.config.manage|hr.attendance.config.approve');")
        ->toContain("Route::get('rosters', [AttendanceResultController::class,'rosters'])->middleware('permission:hr.attendance.results.view');");
});

it('wires the full calendar/shift/policy/roster read+write set into a new Angular configuration page with no dedicated UI before this', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/services/hr-attendance.service.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-configuration/attendance-configuration.component.ts'));
    $routes = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/hr-attendance.routes.ts'));

    expect($service)
        ->toContain("calendars(params: any = {}) { return this.makeGetCall('/hr/attendance/calendars', params); }")
        ->toContain("approvePolicy(id: string) { return this.makePostCall(`/hr/attendance/policies/\${id}/approve`, {}); }")
        ->toContain("approveRoster(id: string) { return this.makePostCall(`/hr/attendance/rosters/\${id}/approve`, {}); }");
    expect($component)
        ->toContain("canDecidePolicy(row: any) { return this.canApproveConfig() && row.created_by !== this.myUserId && row.status === 'pending_approval'; }")
        ->toContain("canDecideRoster(row: any) { return this.canApproveConfig() && row.created_by !== this.myUserId && !row.approved_at; }");
    expect($routes)->toContain("redirectTo: 'configuration/calendars'")
        ->toContain("['calendars', 'shifts', 'policies', 'rosters']")
        ->toContain('attendanceConfigurationSection');
});
