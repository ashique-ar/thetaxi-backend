<?php

it('keeps Workforce Staff pickers bounded, tenant-scoped, and mutation-revalidated', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $leave = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));
    $work = file_get_contents(app_path('Services/Hr/Workforce/WorkforceWorkflowService.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/workforce-overview/workforce-overview.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/workforce-overview/workforce-overview.component.html'));
    $leaveConfig = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/leave-configuration/leave-configuration.component.ts'));
    $assignmentDialog = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/leave-configuration/leave-assignment-dialog.component.ts'));
    $selector = file_get_contents(base_path('../portal-thetaxi/src/app/shared/components/ui/managed-record-select/managed-record-select.component.ts'));

    expect($routes)->toContain("Route::get('staff-options', [WorkforceController::class, 'staffOptions'])")
        ->and($controller)->toContain('public function staffOptions(Request $r, StaffAccessService $access)', "'company_id' => ['nullable', 'uuid']", "'per_page' => ['nullable', 'integer', 'min:1', 'max:50']", 'company($r, $data[\'company_id\'] ?? null)', "whereNull('employment_ended_at')", 'scope(Staff::query()')
        ->toContain("'company_id' => \$companyId")
        ->not->toContain("'staff' => \$staff")
        ->and($leave)->toContain('lockForUpdate()->first()', "\$staff->company_id === \$data['company_id']", "\$staff->employment_ended_at === null")
        ->and($work)->toContain('lockForUpdate()->first()', "\$staff->company_id===\$data['company_id']", "\$staff->employment_ended_at===null")
        ->and($template)->toContain('endpoint="/hr/workforce/staff-options"', '[companyId]="leaveForm.controls.company_id.value ||', '[companyId]="workForm.controls.company_id.value ||')
        ->not->toContain('staffOptions()', 'dataSource="static" [options]="staffOptions()"')
        ->and($component)->not->toContain('staff: [],')
        ->and($leaveConfig)->not->toContain('staffOptions()', 'this.staff.set(')
        ->toContain("row?.staff_name", "'Unavailable Staff'")
        ->and($assignmentDialog)->toContain('endpoint="/hr/workforce/staff-options"', 'form.controls.company_id.value')
        ->not->toContain('data.staffOptions', 'dataSource="static"')
        ->and($controller)->toContain('staff.code as staff_code', 'users.first_name as staff_first_name')
        ->and($selector)->toContain('if (this.value) this.clearStaleSelection();');
});
