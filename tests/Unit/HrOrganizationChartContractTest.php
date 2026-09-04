<?php

it('adds a dated, whole-graph organization chart endpoint reusing the reporting-line manager join pattern', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect($controller)
        ->toContain('public function organizationChart(Request $request): JsonResponse')
        ->toContain("->leftJoin('staff as manager_staff', 'manager_staff.id', '=', 'unit.manager_staff_id')")
        ->toContain("->leftJoin('users as manager_user', 'manager_user.id', '=', 'manager_staff.user_id')")
        ->toContain("'manager_staff.code as manager_employee_number', 'manager_user.first_name as manager_first_name', 'manager_user.last_name as manager_last_name'")
        ->toContain("return response()->json(['status' => 'success', 'data' => ['as_of' => \$asOf, 'units' => \$rows]]);");
});

it('counts only active positions effective on the same as-of date, not every position ever created', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect($controller)->toContain("\$positionCounts = DB::table('hr_positions')->where('company_id', \$companyId)->where('status', '!=', 'inactive')");
});

it('registers the chart route under the existing hr.organization.view permission with no new permission minted', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('chart', [PeopleCoreController::class,'organizationChart'])->middleware('permission:hr.organization.view');");
});

it('wires the chart into the existing Angular organization administration page as a client-side flattened hierarchy', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/hr-people.service.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/organization-administration.component.ts'));

    expect($service)->toContain("organizationChart(params: {as_of?:string}={}):Observable<ApiResponse<{as_of:string;units:OrganizationChartUnit[]}>> {");
    expect($component)
        ->toContain('Organization chart')
        ->toContain('private flattenChart(units:OrganizationChartUnit[]):{unit:OrganizationChartUnit;depth:number}[]{')
        ->toContain("managerLabel(unit:OrganizationChartUnit):string{if(!unit.manager_staff_id)return'Unassigned';");
});
