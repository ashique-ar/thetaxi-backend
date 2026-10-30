<?php

it('uses managed selectors for installation-wide workforce plan units and positions', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforcePlanningController.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/analytics-administration/analytics-administration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/analytics-administration/analytics-administration.component.html'));

    expect($routes)->toContain("Route::get('workforce-planning/reference-options', [WorkforcePlanningController::class, 'referenceOptions'])")
        ->and($controller)->toContain("'per_page'=>['nullable','integer','min:1','max:50']")
        ->not->toContain('CompanyIdScope', 'company_id')
        ->and($component)->toContain('UiManagedRecordSelectComponent', 'positionQuery(unitId')
        ->and($template)->toContain('recordType="organization_unit"', 'recordType="position"', '[queryParams]="positionQuery(l.value.organization_unit_id)"')
        ->not->toContain('refs().organization_units', 'positionsFor(');
});
