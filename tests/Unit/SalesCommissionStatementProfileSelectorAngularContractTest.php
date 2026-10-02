<?php

it('uses the permission-scoped searchable profile selector to generate statements', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-operations/sales-commission-operations.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-operations/sales-commission-operations.component.html'));

    expect($routes)->toContain("Route::get('commission-statement-profile-options', [CommissionStatementController::class, 'profileOptions'])")
        ->not->toContain('commission-statements-context', 'managementContext')
        ->and($controller)->toContain("'max:50'", 'configured()', "where('sales_profiles.commission_eligible', true)", 'assertManagementCompany')
        ->and($component)->toContain('UiManagedRecordSelectComponent')
        ->not->toContain('statementContext()', 'profiles=signal<any[]>([])')
        ->and($template)->toContain('endpoint="/sales/commission-statement-profile-options"')
        ->not->toContain('*ngFor="let row of profiles()"');
});
