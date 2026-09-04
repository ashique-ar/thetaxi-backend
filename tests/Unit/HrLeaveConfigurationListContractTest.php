<?php

it('adds the missing leave type, policy, and policy-assignment list endpoints, closing the create-but-no-read gap', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));

    expect($controller)
        ->toContain('public function leaveTypes(Request $r): JsonResponse')
        ->toContain('public function leavePolicies(Request $r): JsonResponse')
        ->toContain('public function leavePolicyAssignments(Request $r, StaffAccessService $access): JsonResponse');
});

it('scopes policy assignments through the same self/team/all StaffAccessService as other Staff-named resources, not company-only', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));
    $method = substr($controller, strpos($controller, 'function leavePolicyAssignments('));
    $method = substr($method, 0, strpos($method, 'public function storeLeaveType'));

    expect($method)->toContain("\$staffIds = \$access->scope(Staff::query(), \$r->user())->select('id');");
});

it('gates type/policy lists to either the manage or approve permission so an approve-only checker can still see pending policies', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::get('leave/types', [WorkforceController::class,'leaveTypes'])->middleware('permission:hr.leave.config.manage|hr.leave.config.approve');")
        ->toContain("Route::get('leave/policies', [WorkforceController::class,'leavePolicies'])->middleware('permission:hr.leave.config.manage|hr.leave.config.approve');")
        ->toContain("Route::get('leave/policy-assignments', [WorkforceController::class,'leavePolicyAssignments'])->middleware('permission:hr.leave.view');");
});

it('wires the full leave-type/policy/assignment read+write set into a new Angular configuration page with no dedicated UI before this', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/hr-workforce.service.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/leave-configuration/leave-configuration.component.ts'));
    $routes = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/hr-workforce.routes.ts'));

    expect($service)
        ->toContain("leaveTypes(params:any={}){return this.makeGetCall('/hr/workforce/leave/types',params);}")
        ->toContain("approveLeavePolicy(id:string){return this.makePostCall(`/hr/workforce/leave/policies/\${id}/approve`,{});}")
        ->toContain("approveLeaveAssignment(id:string){return this.makePostCall(`/hr/workforce/leave/policy-assignments/\${id}/approve`,{});}");
    expect($component)
        ->toContain("canDecidePolicy(row: any) { return this.canApproveConfig() && row.created_by !== this.myUserId && row.status === 'pending_approval'; }")
        ->toContain("canDecideAssignment(row: any) { return this.canApproveConfig() && row.created_by !== this.myUserId && !row.approved_at; }");
    expect($routes)->toContain("{ path: 'leave-configuration', loadComponent: () => import('./components/leave-configuration/leave-configuration.component').then(m => m.LeaveConfigurationComponent), canActivate: [createPermissionGuard(['hr.leave.config.manage', 'hr.leave.config.approve'])] },");
});
