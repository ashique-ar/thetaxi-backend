<?php

it('provides a bounded tenant-scoped interview panel selector', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/RecruitmentController.php'));
    $routes=file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('interview-panel-options', [RecruitmentController::class, 'interviewPanelOptions'])")
        ->and($controller)->toContain('public function interviewPanelOptions(')
        ->toContain("'selected_ids'=>['nullable','array','max:50]")
        ->toContain("where('staff.company_id',\$company)")
        ->toContain("'value'=>(string)\$row->id")
        ->toContain("'status'=>'active'");
});

it('locks and revalidates active same-company panelists before scheduling', function () {
    $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/RecruitmentController.php'));

    expect($controller)
        ->toContain("where('id',\$id)->lockForUpdate()->first()")
        ->toContain("whereIn('id',\$panel)->where('company_id',\$app->company_id)->whereNull('deleted_at')->whereNull('employment_ended_at')->orderBy('id')->lockForUpdate()->get(['id'])")
        ->toContain('Every panel member must be active in the application legal entity.');
});
