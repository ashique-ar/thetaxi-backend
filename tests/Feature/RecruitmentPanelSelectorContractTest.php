<?php

it('provides a bounded installation-wide active Staff interview panel selector', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/RecruitmentController.php'));
    $routes=file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('interview-panel-options', [RecruitmentController::class, 'interviewPanelOptions'])")
        ->and($controller)->toContain('public function interviewPanelOptions(')
        ->toContain("'selected_ids'=>['nullable','array','max:50']")
        ->toContain("whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')")
        ->toContain("where('hr_employment_spells.status','active')")
        ->toContain("'value'=>(string)\$row->id")
        ->toContain("'status'=>'active'")
        ->not->toContain('company_id', 'companyId');
});

it('locks and revalidates active panelists before scheduling', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/RecruitmentController.php'));

    expect($controller)
        ->toContain("where('id',\$id)->lockForUpdate()->first()")
        ->toContain("whereIn('id',\$panel)->whereNull('deleted_at')->whereNull('employment_ended_at')")
        ->toContain('Every panel member must be active Staff with an active employment spell.')
        ->not->toContain('company_id', 'companyId');
});
