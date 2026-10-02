<?php

it('uses the authorized paginated alert owner selector instead of preloading owner IDs', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance-administration/sales-performance-administration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance-administration/sales-performance-administration.component.html'));

    expect($routes)->toContain("Route::get('performance/alert-owner-options', [SalesPerformanceController::class, 'alertOwnerOptions'])")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']", "where('staff.company_id', \$data['company_id'])", "whereNull('staff.deleted_at')", "where('users.is_active', true)", "staff_context.context_type", "staff_context.is_active")
        ->not->toContain("'alert_owners' =>")
        ->and($component)->not->toContain('alertOwners', 'policyAlertOwners')
        ->and($template)->toContain('endpoint="/sales/performance/alert-owner-options"', '[companyId]="policy.company_id"')
        ->not->toContain('name="alertOwner"><mat-option', 'policyAlertOwners()');
});
