<?php

it('keeps tenant decision company choice bounded explicit and stale safe', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Admin/TenantDecisionController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/system/tenant-decisions/tenant-decisions.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/system/tenant-decisions/tenant-decisions.component.html'));

    expect($routes)->toContain("Route::get('company-options'")
        ->and($controller)->toContain("'per_page' => 'nullable|integer|min:1|max:50'", "whereNull('deleted_at')", "whereNull('employment_ended_at')", "where('user_id', $r->user()->id)", "'selected_id' => 'nullable|uuid'")
        ->and($component)->toContain('private requestVersion = 0', 'version !== this.requestVersion', 'companyId !== this.companyId')
        ->not->toContain('this.companies()[0]')
        ->and($template)->toContain('endpoint="/tenant-decisions/company-options"', 'title="Select a legal entity"');
});
