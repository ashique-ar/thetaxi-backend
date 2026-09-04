<?php

it('locks and validates every review assignment reference inside one legal entity', function () {
    $service = file_get_contents(app_path('Services/Hr/Talent/PerformanceManagementService.php'));

    expect($service)
        ->toContain("where('company_id', \$data['company_id'])->where('status', 'approved')->lockForUpdate()")
        ->toContain("whereKey(\$data['staff_id'])->where('company_id', \$data['company_id'])->whereNull('employment_ended_at')->lockForUpdate()")
        ->toContain("abort_if(\$managerId === \$staff->id")
        ->toContain("whereKey(\$managerId)->where('company_id', \$data['company_id'])->whereNull('employment_ended_at')");
});

it('rejects cross-employee or cross-company review and parent goal references', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PerformanceController.php'));

    expect($controller)
        ->toContain("where('id',\$data['review_id'])->where('company_id',\$actor->company_id)->where('staff_id',\$staff->id)")
        ->toContain("where('id',\$data['parent_goal_id'])->where('company_id',\$actor->company_id)->where('staff_id',\$staff->id)")
        ->toContain("(\$parent->review_id??null)===(\$data['review_id']??null)");
});

it('serializes goal weight checks with the employee row and active goal set', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PerformanceController.php'));

    expect($controller)
        ->toContain("whereKey(\$data['staff_id'])->where('company_id',\$actor->company_id)->lockForUpdate()")
        ->toContain("whereNotIn('status',['cancelled'])->select('weight')->lockForUpdate()->get()")
        ->toContain("\$activeGoals->sum('weight')");
});
