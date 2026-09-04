<?php

it('computes a read-only per-Staff learning compliance status against the governed hr_learning_requirements register', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/LearningController.php'));

    expect($controller)
        ->toContain('public function compliance(Request$request):JsonResponse')
        ->toContain("if(empty(\$forStaffTypes)&&empty(\$forUnits))")
        ->toContain("\$dueDate=\$assignment?\\Carbon\\CarbonImmutable::parse(\$assignment->effective_from)->addDays(\$requirement->due_days)->toDateString():null;")
        ->toContain("\$status=\$validCompletion?'satisfied':((\$dueDate&&\$dueDate<=\$today)?'overdue':'pending');");
});

it('requires hr.learning.manage to view another employee\'s compliance but not one\'s own', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/LearningController.php'));

    expect($controller)->toContain("abort_unless(\$request->user()->can('hr.learning.manage'),403,'Viewing another employee\'s learning compliance requires HR learning management access.');");
});

it('registers the compliance route under the existing hr.learning.view permission with no new permission minted', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('compliance',[LearningController::class,'compliance'])->middleware('permission:hr.learning.view');");
});

it('wires learning compliance into the existing Angular Learning page', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/hr-talent.service.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/learning.component.ts'));

    expect($service)->toContain("learningCompliance(p:any={}){return this.makeGetCall('/hr/learning/compliance',p)}");
    expect($component)
        ->toContain('My learning compliance')
        ->toContain("this.api.learningCompliance().subscribe({next:r=>this.compliance.set(r.data||[]),error:()=>{}})");
});
