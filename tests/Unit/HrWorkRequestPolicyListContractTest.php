<?php

it('lists work-request policies scoped to the actor legal entity via the existing company() helper, closing a create-but-no-read gap', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));
    $method = substr($controller, strpos($controller, 'public function workRequestPolicies('));
    $method = substr($method, 0, strpos($method, 'public function storeWorkPolicy('));

    expect($method)
        ->toContain("\$companyId=\$this->company(\$r,\$r->input('company_id'));")
        ->toContain("DB::table('hr_work_request_policies')->where('company_id',\$companyId)")
        ->not->toContain('->insert(')
        ->not->toContain('->update(');
});

it('registers the list route with the existing OR-permission pattern used for leave policies, minting no new permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('work-request-policies', [WorkforceController::class,'workRequestPolicies'])->middleware('permission:hr.work-requests.config.manage|hr.work-requests.config.approve');");
});

it('gates the new work-request-configuration Angular route on the same OR-permission pair as the backend list route', function () {
    $routes = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/hr-workforce.routes.ts'));

    expect($routes)->toContain("createPermissionGuard(['hr.work-requests.config.manage', 'hr.work-requests.config.approve'])");
});

it('wires the previously-missing timesheet transition UI onto the existing transitionTimesheet() service call, not a new endpoint', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/workforce-overview.component.ts'));

    expect($component)
        ->toContain('canDecideTimesheet(row:any){return this.canApproveTimesheets()&&row.submitted_by!==this.myUserId&&row.status===\'submitted\';}')
        ->toContain('this.api.transitionTimesheet(a.id,{action:a.action,reason:v.reason})');
});
