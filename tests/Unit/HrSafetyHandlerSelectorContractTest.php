<?php

it('uses bounded tenant-scoped readable selectors for safety investigators and action owners', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/SafetyController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-relations/components/safety-detail/safety-detail.component.html'));

    expect($routes)->toContain("Route::get('handler-candidates', [SafetyController::class, 'handlerCandidates'])->middleware('permission:hr.safety.manage');")
        ->and($controller)->toContain("Rule::in(['investigator','action_owner'])")
        ->and($controller)->toContain("abort_unless(\$a->company_id===\$d['company_id'],403")
        ->and($controller)->toContain("'per_page'=>['nullable','integer','min:1','max:50']")
        ->and($controller)->toContain("whereNull('staff.employment_ended_at')")
        ->and($controller)->toContain("where('users.is_active',true)")
        ->and($controller)->toContain("whereHas('user.permissions'")
        ->and($controller)->toContain("orWhereHas('user.roles.permissions'")
        ->and($component)->toContain('recordType="investigator"')
        ->and($component)->toContain('recordType="action_owner"');
});
