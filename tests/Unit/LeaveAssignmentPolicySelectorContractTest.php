<?php

it('uses a bounded interval-valid managed policy selector for leave assignments', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $dialog = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/leave-configuration/leave-assignment-dialog.component.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/leave-configuration/leave-configuration.component.ts'));

    expect($routes)->toContain("Route::get('leave-assignment-policy-options', [WorkforceController::class, 'leaveAssignmentPolicyOptions'])")
        ->and($controller)->toContain("where('policy.status', 'approved')")
        ->toContain("whereDate('policy.effective_from', '<=', \$d['effective_from'])")
        ->toContain("The approved policy must cover the full assignment period.")
        ->toContain("whereNull('employment_ended_at')")
        ->and($dialog)->toContain('endpoint="/hr/workforce/leave-assignment-policy-options"')
        ->toContain('effective_until: form.controls.effective_until.value')
        ->not->toContain('DynamicSelectComponent')
        ->and($component)->not->toContain('policyOptions()');
});
