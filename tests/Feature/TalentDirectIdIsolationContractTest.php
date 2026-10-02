<?php

it('binds feedback review and goal references to one employee and legal entity', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TalentController.php'));

    expect($controller)
        ->toContain("where('id',\$d['review_id'])->where('company_id',\$author->company_id)->where('staff_id',\$subject->id)")
        ->toContain("where('id',\$d['goal_id'])->where('company_id',\$author->company_id)->where('staff_id',\$subject->id)")
        ->toContain("\$goal->review_id===\$d['review_id']");
});

it('binds recommendation evidence to the selected active employee', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TalentController.php'));

    expect($controller)
        ->toContain("whereKey(\$d['staff_id'])->where('company_id',\$a->company_id)->whereNull('employment_ended_at')")
        ->toContain("where('id',\$d['review_id'])->where('company_id',\$a->company_id)->where('staff_id',\$d['staff_id'])");
});

it('revalidates promotion employee approver and replay result inside one company', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TalentController.php'));

    expect($controller)
        ->toContain("where('id',\$row->result_change_request_id)->where('company_id',\$row->company_id)")
        ->toContain("whereKey(\$row->staff_id)->where('company_id',\$row->company_id)->whereNull('employment_ended_at')->lockForUpdate()")
        ->toContain("abort_if(\$d['approver_staff_id']===\$staff->id")
        ->toContain("whereKey(\$d['approver_staff_id'])->where('company_id',\$row->company_id)->whereNull('employment_ended_at')");
});

it('requires succession nominees to remain active in the role company', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TalentController.php'));

    expect($controller)
        ->toContain("whereKey(\$d['staff_id'])->where('company_id',\$a->company_id)->whereNull('employment_ended_at')->exists()");
});

it('serializes talent profile upserts and requires active non-self development managers', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TalentController.php'));

    expect($controller)
        ->toContain("whereKey(\$staffId)->where('company_id',\$actor->company_id)->whereNull('employment_ended_at')->lockForUpdate()")
        ->toContain("where('staff_id',\$staff->id)->lockForUpdate()->first()")
        ->toContain("abort_if(\$d['manager_staff_id']===\$staff->id")
        ->toContain("whereKey(\$d['manager_staff_id'])->where('company_id',\$a->company_id)->whereNull('employment_ended_at')");
});
