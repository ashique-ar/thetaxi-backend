<?php

it('uses a bounded confidential-case-aware hearing chair selector and retains write-time date and conflict checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/RelationsCaseController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-relations/components/case-detail/case-detail.component.html'));

    expect($routes)->toContain("Route::get('hearing-chair-candidates', [RelationsCaseController::class, 'hearingChairCandidates'])->middleware('permission:hr.relations.case.transition');")
        ->and($controller)->toContain("\$this->authorized(\$r,\$d['case_id'],'transition')")
        ->and($controller)->toContain('currentActorStaff($r->user())')
        ->and($controller)->not->toContain("'company_id'=>['required','uuid']")
        ->and($controller)->toContain("'per_page'=>['nullable','integer','min:1','max:50']")
        ->and($controller)->toContain("whereDate('team.effective_from','<=',today())")
        ->and($controller)->toContain("where('staff.employment_ended_at','>',now())")
        ->and($controller)->toContain("whereNotExists(fn(\$conflict)")
        ->and($controller)->toContain("whereDate('effective_from','<=',\$hearingDate)")
        ->and($controller)->toContain("The proposed chair has an unresolved conflict.")
        ->and($component)->toContain('app-ui-managed-record-select')
        ->and($component)->toContain('[queryParams]="hearingChairQueryParams"')
        ->and($component)->not->toContain('[companyId]')
        ->and($component)->not->toContain('{{ m.team_role }} · {{ m.staff_id }}');
});
