<?php

it('lists expense policy versions scoped to the actor legal entity via the existing actor()/company_id pattern', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/ServiceOperationsController.php'));
    $method = substr($controller, strpos($controller, 'public function expensePolicyVersions('));
    $method = substr($method, 0, strpos($method, 'public function storePolicy('));

    expect($method)
        ->toContain('$a=$this->actor($r);')
        ->toContain("DB::table('hr_expense_policy_versions')->where('company_id',\$a->company_id)")
        ->not->toContain('->insert(')
        ->not->toContain('->update(');
});

it('registers the list route with the existing OR-permission pattern used for leave/work-request policies, minting no new permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('expense-policies',[ServiceOperationsController::class,'expensePolicyVersions'])->middleware('permission:hr.expenses.policy.manage|hr.expenses.policy.approve');");
});

it('gates the new expense-configuration Angular route on the same OR-permission pair as the backend list route', function () {
    $routes = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/hr-talent.routes.ts'));

    expect($routes)->toContain("createPermissionGuard(['hr.expenses.policy.manage','hr.expenses.policy.approve'])");
});

it('wires expense-claim decision confidentiality: the submitter cannot decide their own claim, mirrored client-side', function () {
    $service = file_get_contents(app_path('Http/Controllers/Api/Hr/ServiceOperationsController.php'));
    $decideMethod = substr($service, strpos($service, 'public function decideClaim('));
    $decideMethod = substr($decideMethod, 0, strpos($decideMethod, 'public function tickets('));

    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/components/service-operations/service-operations.component.ts'));

    expect($decideMethod)->toContain("abort_if(\$c->submitted_by===\$r->user()->id,409");
    expect($component)->toContain("canDecideClaim(row: any) { return this.canApproveClaims() && row.submitted_by !== this.myUserId && row.status === 'pending_approval'; }");
});

it('wires the HR service-desk ticket transition UI onto the existing backend allowed-transition map, not an invented one', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/ServiceOperationsController.php'));
    $backendMap = substr($controller, strpos($controller, "\$allowed=["), strpos($controller, "abort_unless(in_array(\$d['to']") - strpos($controller, "\$allowed=["));

    expect($backendMap)->toContain("'open'=>['assigned','in_progress']");

    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/components/service-operations/service-operations.component.ts'));
    expect($component)->toContain("open: ['assigned', 'in_progress']");
});

it('uses a tenant-scoped readable selector and revalidates service ticket assignee eligibility on write', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/ServiceOperationsController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $dialog = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/components/service-operations/dialogs/ticket-transition-dialog.component.ts'));

    expect($routes)->toContain("Route::get('requests/assignee-options', [ServiceOperationsController::class, 'ticketAssigneeOptions'])->middleware('permission:hr.service-desk.manage');")
        ->and($controller)->toContain("abort_unless(\$a->company_id===\$d['company_id'],403")
        ->and($controller)->toContain("whereNull('staff.employment_ended_at')")
        ->and($controller)->toContain("where('users.is_active',true)")
        ->and($controller)->toContain("whereHas('user.permissions'")
        ->and($controller)->toContain("orWhereHas('user.roles.permissions'")
        ->and($controller)->toContain("Assignee must be active and authorized to manage HR service requests.")
        ->and($dialog)->toContain('app-ui-managed-record-select')
        ->and($dialog)->toContain('[companyId]="data.ticket.company_id"')
        ->and($dialog)->not->toContain('Assign to Staff ID');
});
