<?php

it('uses bounded corporate-scoped department and division selectors in employee setup', function () {
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/corporate/components/employee-form/employee-form.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/corporate/components/employee-form/employee-form.component.ts'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateEmployeeController.php'));

    expect($template)->toContain('endpoint="/corporate/employee-reference-options"')
        ->toContain('recordType="department"')
        ->toContain('recordType="division"')
        ->toContain('[queryParams]="divisionReferenceParams"')
        ->not->toContain('<mat-select formControlName="department_id">')
        ->not->toContain('<mat-select formControlName="division_id">')
        ->and($component)->toContain('UiManagedRecordSelectComponent')
        ->toContain('department_id: departmentId')
        ->not->toContain('getDepartments()')
        ->and($routes)->toContain("Route::get('employee-reference-options'")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("where('corporate_id', \$request->corporate_id)")
        ->toContain("where('department_id', \$department->id)");
});
