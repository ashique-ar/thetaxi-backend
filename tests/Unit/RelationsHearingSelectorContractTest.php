<?php

it('uses a confidential case-scoped bounded selector and keeps completion checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/RelationsCaseController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-relations/components/case-detail/case-detail.component.html'));

    expect($routes)->toContain("Route::get('cases/{id}/hearing-options', [RelationsCaseController::class, 'hearingOptions'])->whereUuid('id')->middleware('permission:hr.relations.case.transition');")
        ->and($controller)->toContain("\$this->authorized(\$r,\$id,'transition')")
        ->and($controller)->toContain("where('case_id',\$case->id)->where('status','scheduled')")
        ->and($controller)->toContain("'max:50'")
        ->and($controller)->toContain("abort_unless(\$h->status==='scheduled',409)")
        ->and($component)->toContain("'/hr/relations/cases/' + id + '/hearing-options'")
        ->and($component)->not->toContain('scheduledHearings(d.hearings)');
});
