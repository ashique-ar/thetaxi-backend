<?php

it('adds the missing lifecycle template list endpoint so openCase()\'s required template_id can be discovered and pending templates can be found to approve', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/LifecycleController.php'));

    expect($controller)
        ->toContain('public function templates(Request$r):JsonResponse{')
        ->toContain("\$companyId=Staff::query()->where('user_id',\$r->user()->id)->value('company_id');")
        ->toContain("'applicability'=>json_decode(\$row->applicability,true,512,JSON_THROW_ON_ERROR),'task_definitions'=>json_decode(\$row->task_definitions,true,512,JSON_THROW_ON_ERROR)");
});

it('gates the template list to either the manage or approve permission so an approve-only checker can still see pending templates', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('templates',[LifecycleController::class,'templates'])->middleware('permission:hr.lifecycle.manage|hr.lifecycle.approve');");
});

it('wires lifecycle templates into the Angular lifecycle-cases page, including an approved-only template selector for opening a case', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-lifecycle/hr-lifecycle.service.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-lifecycle/components/lifecycle-cases/lifecycle-cases.component.ts'));

    expect($service)->toContain("templates(p:any={}){return this.makeGetCall('/hr/lifecycle/templates',p)}");
    expect($component)
        ->toContain("approvedTemplates() { return this.templates().filter(t => t.status === 'approved'); }")
        ->toContain("canDecideTemplate(row: any) { return this.canApproveConfig() && row.created_by !== this.myUserId && row.status === 'pending_approval'; }");
});
