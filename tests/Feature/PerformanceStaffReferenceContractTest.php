<?php

it('assigns reviews using active Staff and current employment assignments', function () {
    $service = file_get_contents(app_path('Services/Hr/Talent/PerformanceManagementService.php'));

    expect($service)
        ->toContain("where('id', \$data['cycle_id'])->where('status', 'approved')")
        ->toContain("where('id', \$data['template_id'])->where('status', 'approved')")
        ->toContain("whereKey(\$data['staff_id'])->whereNull('employment_ended_at')->lockForUpdate()->first()")
        ->toContain("where('staff_id', \$staff->id)")
        ->toContain('The reviewed employee cannot be their own manager.')
        ->toContain('An active manager is required.')
        ->not->toContain('company_id');
});

it('binds review and parent-goal references to the same Staff identity', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PerformanceController.php'));

    expect($controller)
        ->toContain("where('id',\$data['review_id'])->where('staff_id',\$staff->id)")
        ->toContain("where('id',\$data['parent_goal_id'])->where('staff_id',\$staff->id)")
        ->toContain('A parent goal must belong to the same review context.')
        ->toContain("whereKey(\$data['staff_id'])->lockForUpdate()")
        ->toContain("whereNotIn('status',['cancelled'])->select('weight')->lockForUpdate()->get()")
        ->not->toContain('company_id');
});
