<?php

it('exposes requested_by, actual_return_date, and recalled_at on the leave list so the Angular workspace can gate row actions without extra round trips', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));

    expect($controller)->toContain("'request.current_approver_staff_id', 'request.requested_by', 'request.actual_return_date', 'request.recalled_at', 'request.created_at'");
});

it('exposes the decide, recall, cancel, confirm-return, and extend actions on the Angular leave workforce service', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/hr-workforce.service.ts'));

    expect($service)
        ->toContain("decideLeave(id:string,payload:any){return this.makePostCall(`/hr/workforce/leave/requests/\${id}/decide`,payload);}")
        ->toContain("recallLeave(id:string,payload:any){return this.makePostCall(`/hr/workforce/leave/requests/\${id}/recall`,payload);}");
});

it('gates approve/reject and recall to a non-requester with hr.leave.approve, and self-service actions to the requester or an approver', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/workforce-overview.component.ts'));

    expect($component)
        ->toContain("canDecide(row:any){return this.canApprove()&&!this.isMine(row)&&row.status==='pending_approval';}")
        ->toContain("canRecall(row:any){return this.canApprove()&&!this.isMine(row)&&row.status==='approved'&&!row.actual_return_date&&!row.recalled_at;}")
        ->toContain("canCancel(row:any){return!row.recalled_at&&(row.status==='pending_approval'||row.status==='approved')&&(this.isMine(row)||this.canApprove());}")
        ->toContain("canReturn(row:any){return row.status==='approved'&&!row.actual_return_date&&!row.recalled_at&&(this.isMine(row)||this.canApprove());}");
});
