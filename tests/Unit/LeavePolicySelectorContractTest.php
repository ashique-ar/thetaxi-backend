<?php

it('uses assigned interval-valid leave policy choices for the workforce request form', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/workforce-overview/workforce-overview.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/workforce-overview/workforce-overview.component.html'));

    expect($routes)->toContain("Route::get('leave-policy-options', [WorkforceController::class, 'leavePolicyOptions'])")
        ->and($controller)->toContain("where('assignment.staff_id', \$d['staff_id'])")
        ->toContain("whereNotNull('assignment.approved_at')")
        ->toContain("whereDate('assignment.effective_from', '<=', \$d['start_date'])")
        ->toContain("whereDate('policy.effective_from', '<=', \$d['start_date'])")
        ->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->and($service)->toContain('No approved leave policy covers the requested interval.')
        ->toContain('The leave policy is not assigned for the full interval.')
        ->and($template)->toContain('endpoint="/hr/workforce/leave-policy-options"')
        ->toContain('leavePolicyLookupReady()')->not->toContain('leavePolicyOptions()')
        ->and($component)->not->toContain("this.ensureReferences(done, this.leaveLoading)");
});
