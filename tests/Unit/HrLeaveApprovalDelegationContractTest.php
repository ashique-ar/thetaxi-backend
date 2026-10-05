<?php

it('lets an approved leave-type delegate of the assigned approver decide a request without needing the override permission', function () {
    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));

    expect($service)
        ->toContain("DB::table('hr_approval_delegations as delegation')", "join('staff as approver'", "approved_by_staff_id", "->on('approver.user_id', '=', 'delegation.approved_by')")
        ->toContain("where('delegation.company_id', \$row->company_id)", "where('delegation.delegate_staff_id', \$actorStaffId)", "whereNotNull('delegation.approved_at')")
        ->toContain("in_array('leave', json_decode(\$delegation->request_types, true) ?: [], true)")
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
