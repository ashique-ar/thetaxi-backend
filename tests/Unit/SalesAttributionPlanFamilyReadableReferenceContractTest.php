<?php

it('uses company-matched plan evidence and readable labels in attribution correction previews', function () {
    $resolver = file_get_contents(app_path('Services/Sales/CommissionPlanResolver.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-attribution-operations/sales-attribution-operations.component.html'));

    expect($resolver)
        ->toContain("->with('planFamily:id,company_id,code,name,commission_category,status')")
        ->toContain("where('company_id', \$attribution->company_id)")
        ->toContain("'plan_family_reference' => ['code' => \$family->code, 'name' => \$family->name]")
        ->toContain("'assignment_reference' => [")
        ->toContain("'plan_family_id' => \$assignment->plan_family_id", "'plan_assignment_id' => \$assignment->id")
        ->toContain("'frozen_correction_snapshot' => \$snapshot")
        ->and($controller)
        ->toContain("unset(\$preview['frozen_correction_snapshot'], \$preview['prohibited_approver_ids'])")
        ->and($routes)
        ->toContain('attributions/{attribution}/plan-family-correction-preview', 'permission:sales.attributions.correct')
        ->and($template)
        ->toContain('preview.plan_family_reference.code', 'preview.plan_family_reference.name', 'preview.assignment_reference.scope_type', 'preview.assignment_reference.effective_until', 'preview.secured_at')
        ->not->toContain('preview.frozen_correction_snapshot.plan_family_id', 'preview.frozen_correction_snapshot.plan_assignment_id');
});
