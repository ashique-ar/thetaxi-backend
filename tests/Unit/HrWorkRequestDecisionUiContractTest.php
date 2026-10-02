<?php

it('exposes decideWork() at the existing hr.work-requests.approve route with no new permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::post('work-requests/{id}/decide', [WorkforceController::class,'decideWork'])->whereUuid('id')->middleware('permission:hr.work-requests.approve');");
});

it('keeps work-request decisions maker-checker: the requester cannot decide their own request', function () {
    $service = file_get_contents(app_path('Services/Hr/Workforce/WorkforceWorkflowService.php'));
    $method = substr($service, strpos($service, 'public function decideWorkRequest('));
    $method = substr($method, 0, strpos($method, 'public function saveTimesheet('));

    expect($method)
        ->toContain("abort_if(\$row->requested_by===\$actor,409")
        ->toContain("abort_unless(\$row->status==='pending_approval',409");
});

it('wires an Angular decide call for work requests using the existing service base, mirroring the leave decision pattern', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/hr-workforce.service.ts'));

    expect($service)->toContain("decideWork(id:string,payload:any){return this.makePostCall(`/hr/workforce/work-requests/\${id}/decide`,payload);}");
});

it('gates the work-request Approve/Reject UI on hr.work-requests.approve and excludes the requester\'s own request', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/workforce-overview/workforce-overview.component.ts'));

    expect($component)
        ->toContain("this.auth.hasPermission('hr.work-requests.approve').subscribe(ok=>this.canApproveWork.set(ok));")
        ->toContain("canDecideWork(row:any){return this.canApproveWork()&&!this.isMine(row)&&row.status==='pending_approval';}");
});
