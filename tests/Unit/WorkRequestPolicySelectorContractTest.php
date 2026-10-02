<?php

it('uses a bounded authorized work-policy selector for the selected Staff interval', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $workflow = file_get_contents(app_path('Services/Hr/Workforce/WorkforceWorkflowService.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/workforce-overview/workforce-overview.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/workforce-overview/workforce-overview.component.html'));

    expect($routes)->toContain("Route::get('work-request-policy-options', [WorkforceController::class, 'workRequestPolicyOptions'])")
        ->and($controller)->toContain("where('company_id', \$companyId)")
        ->toContain("where('request_kind', \$d['request_kind'])")
        ->toContain("where('status', 'approved')")
        ->toContain("whereDate('effective_from', '<=', \$start)")
        ->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->and($workflow)->toContain("where('id',\$data['policy_id'])")
        ->toContain("No approved work-request policy covers this interval.")
        ->and($template)->toContain('endpoint="/hr/workforce/work-request-policy-options"')
        ->toContain('workPolicyLookupReady()')->toContain('starts_at: workForm.controls.starts_at.value')
        ->and($component)->not->toContain('workPolicyOptions()');
});
