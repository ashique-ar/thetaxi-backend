<?php

it('adds a separate deny-by-default permission for overriding the assigned leave approver', function () {
    $seeder = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    expect($seeder)
        ->toContain("'hr.leave.approve.override',")
        ->and(substr_count($seeder, "'hr.leave.approve.override',"))->toBe(2);
});

it('lets an authorized override actor decide a leave request assigned to a different approver, recording the override on the decision evidence', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain('public function decide(string $requestId, string $action, string $reason, string $actorUserId, bool $overrideAuthorized = false): object')
        ->toContain('if ($row->current_approver_staff_id && $actorStaff !== $row->current_approver_staff_id) {')
        ->toContain("abort_unless(\$overrideAuthorized, 403, 'This leave request is assigned to a different approver.');")
        ->toContain('$overrideUsed = true;')
        ->toContain("\$snapshot['hr_override'] = ['assigned_approver_staff_id' => \$row->current_approver_staff_id, 'overridden_by' => \$actorUserId];");
});

it('never lets the override bypass the requester-cannot-decide-own-request or pending-status guards', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain("abort_if(\$row->requested_by === \$actorUserId, 409, 'The leave requester cannot decide the same request.');")
        ->toContain("abort_unless(\$row->status === 'pending_approval', 409, 'Only pending leave may be decided.');")
        ->toContain("abort_unless(in_array(\$action, ['approve', 'reject'], true), 422, 'Unsupported leave decision.');")
        ->toContain('$overrideUsed = false;');
});

it('requires the caller to hold the override permission separately from the base decideLeave route gate', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain("\$row = \$service->decide(\$id, \$d['action'], \$d['reason'], \$r->user()->id, \$r->user()->can('hr.leave.approve.override'));")
        ->and($routes)
        ->toContain("Route::post('leave/requests/{id}/decide', [WorkforceController::class,'decideLeave'])->whereUuid('id')->middleware('permission:hr.leave.approve');");
});
