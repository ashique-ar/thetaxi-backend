<?php

it('lets an approved leave-type delegate of the assigned approver decide a request without needing the override permission', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain("\$delegateUsed = DB::table('hr_approval_delegations')->where('delegator_staff_id', \$row->current_approver_staff_id)->where('delegate_staff_id', \$actorStaff)->where('status', 'approved')->whereDate('effective_from', '<=', now())->whereDate('effective_until', '>=', now())->get(['request_types'])->contains(fn(\$delegation) => in_array('leave', json_decode(\$delegation->request_types, true), true));")
        ->toContain('if (!$delegateUsed) {')
        ->toContain("\$snapshot['hr_delegate_decision'] = ['delegator_staff_id' => \$row->current_approver_staff_id, 'decided_by' => \$actorUserId];");
});

it('reuses the existing generic ESS approval-delegation register rather than inventing a Leave-specific delegation table', function () {
    $migration = file_get_contents(base_path('database/migrations/2026_08_13_103000_create_hr_ess_recruitment_and_lifecycle.php'));

    expect($migration)
        ->toContain("Schema::create('hr_approval_delegations',function(Blueprint\$t){")
        ->toContain("\$t->json('request_types');");
});

it('scopes the leave delegation match to requests whose projected request_type is exactly leave', function () {
    $projection = file_get_contents(app_path('Services/Hr/Ess/HrDomainRequestProjectionService.php'));

    expect($projection)->toContain("'leave','leave_request'");
});
