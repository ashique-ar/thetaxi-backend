<?php

it('uses a searchable eligible expense policy selector backed by the submission policy checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/ServiceOperationsController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/components/service-operations/service-operations.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/components/service-operations/service-operations.component.html'));

    expect($routes)->toContain("Route::get('expense-policy-options', [ServiceOperationsController::class, 'expensePolicyOptions'])")
        ->and($controller)->toContain("where('company_id',$a->company_id)->where('status','approved')")
        ->toContain("where('id',$d['policy_version_id'])->where('company_id',$a->company_id)")
        ->toContain("whereDate('effective_from','<=',now())")
        ->toContain("orWhereDate('effective_until','>=',now())")
        ->toContain("'per_page'=>['nullable','integer','min:1','max:50']")
        ->and($template)->toContain('endpoint="/hr/service-operations/expense-policy-options"')
        ->and($component)->not->toContain('approvedPolicies()')->not->toContain('expensePolicies().subscribe');
});
