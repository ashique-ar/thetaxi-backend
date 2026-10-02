<?php

it('uses a bounded approved-template selector without loading templates into the case dialog', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/LifecycleController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $service = file_get_contents(app_path('Services/Hr/Lifecycle/LifecycleService.php'));
    $dialog = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-lifecycle/components/lifecycle-cases/open-case-dialog.component.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-lifecycle/components/lifecycle-cases/lifecycle-cases.component.ts'));

    expect($routes)->toContain("Route::get('template-options', [LifecycleController::class, 'templateOptions'])")
        ->and($controller)->toContain("where('company_id',\$d['company_id'])")
        ->toContain("where('status','approved')")
        ->toContain("'per_page'=>['nullable','integer','min:1','max:50']")
        ->and($service)->toContain("where('status', 'approved')")
        ->toContain('An approved lifecycle template is required.')
        ->and($dialog)->toContain('endpoint="/hr/lifecycle/template-options"')
        ->not->toContain('data.approvedTemplates')
        ->and($component)->not->toContain('approvedTemplates()');
});
