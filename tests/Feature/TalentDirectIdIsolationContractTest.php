<?php

it('binds feedback review and goal references to the selected employee', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TalentController.php'));

    expect($controller)
        ->toContain("where('id',\$d['review_id'])->where('staff_id',\$subject->id)")
        ->toContain("where('id',\$d['goal_id'])->where('staff_id',\$subject->id)")
        ->toContain("\$goal->review_id===\$d['review_id']")
        ->not->toContain('company_id');
});

it('binds recommendation evidence to the selected active employee', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TalentController.php'));

    expect($controller)
        ->toContain("whereKey(\$d['staff_id'])->whereNull('employment_ended_at')")
        ->toContain("where('id',\$d['review_id'])->where('staff_id',\$d['staff_id'])")
        ->not->toContain('company_id');
});

it('revalidates promotion employee, approver, and replay result installation-wide', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TalentController.php'));

    expect($controller)
        ->toContain("where('id',\$row->result_change_request_id)")
        ->toContain("whereKey(\$row->staff_id)->whereNull('employment_ended_at')->lockForUpdate()")
        ->toContain("abort_if(\$d['approver_staff_id']===\$staff->id")
        ->toContain("whereKey(\$d['approver_staff_id'])->whereNull('employment_ended_at')")
        ->not->toContain('company_id');
});

it('requires succession nominees to remain active Staff', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TalentController.php'));

    expect($controller)
        ->toContain("whereKey(\$d['staff_id'])->whereNull('employment_ended_at')->exists()")
        ->not->toContain('company_id');
});

it('serializes talent profile upserts and requires active non-self development managers', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TalentController.php'));

    expect($controller)
        ->toContain("whereKey(\$staffId)->whereNull('employment_ended_at')->lockForUpdate()")
        ->toContain("where('staff_id',\$staff->id)->lockForUpdate()->first()")
        ->toContain("abort_if(\$d['manager_staff_id']===\$staff->id")
        ->toContain("whereKey(\$d['manager_staff_id'])->whereNull('employment_ended_at')")
        ->not->toContain('company_id');
});
