<?php

it('resolves readable Staff labels only through already-authorized restricted relationships', function () {
    $relations = file_get_contents(app_path('Http/Controllers/Api/Hr/RelationsCaseController.php'));
    $safety = file_get_contents(app_path('Http/Controllers/Api/Hr/SafetyController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $caseView = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-relations/components/case-detail/case-detail.component.html'));
    $safetyView = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-relations/components/safety-detail/safety-detail.component.html'));

    expect($routes)->toContain("Route::get('cases/{id}/staff-labels', [RelationsCaseController::class, 'staffLabels')")
        ->and($routes)->toContain("Route::get('incidents/{id}/staff-labels', [SafetyController::class, 'incidentStaffLabels')")
        ->and($relations)->toContain("\$this->authorized(\$r,\$id,'view_case')")
        ->and($relations)->toContain("when(!\$admin,fn(\$q)=>\$q->where('identity_restricted',false))")
        ->and($relations)->toContain("restricted_case_relationship_labels_viewed")
        ->and($safety)->toContain("\$this->visible(\$r,\$id)")
        ->and($relations)->toContain('Staff::withTrashed()')
        ->and($safety)->toContain('Staff::withTrashed()')
        ->and($caseView)->not->toContain('{{ m.staff_id }}')->not->toContain('{{ p.staff_id }}')
        ->and($safetyView)->not->toContain('Owner {{ a.owner_staff_id }}')
        ->and($caseView)->toContain('staffLabel(m.staff_id)')
        ->and($safetyView)->toContain('staffLabel(a.owner_staff_id)');
});
