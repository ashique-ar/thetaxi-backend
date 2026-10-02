<?php

it('uses one bounded leave-type selector in leave and time-off policy setup', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $leave = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/leave-configuration/leave-configuration.component.html'));
    $workPolicy = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/work-request-configuration/work-request-policy-dialog.component.ts'));
    $workConfig = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/work-request-configuration/work-request-configuration.component.ts'));

    expect($routes)->toContain("Route::get('leave-type-options', [WorkforceController::class, 'leaveTypeOptions'])")
        ->and($controller)->toContain("where('company_id', \$companyId)")
        ->toContain("where('status', \$status)")
        ->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("where('company_id', \$d['company_id'])")
        ->not->toContain("'leave_types' => DB::table('hr_leave_types')")
        ->and($leave)->toContain('endpoint="/hr/workforce/leave-type-options"')
        ->and($workPolicy)->toContain('endpoint="/hr/workforce/leave-type-options"')
        ->toContain("status: 'active'")
        ->and($workConfig)->not->toContain('leaveTypeOptions()');
});
