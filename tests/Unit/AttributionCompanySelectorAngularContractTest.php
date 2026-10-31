<?php

it('keeps attribution administration installation-wide without a company selector', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-attribution-operations/sales-attribution-operations.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-attribution-operations/sales-attribution-operations.component.html'));

    expect($routes)->not->toContain("attribution-company-options", "establish-legal-entity")
        ->and($controller)->not->toContain("'company_id' => ['nullable'", "'default_company_id'", 'resolveCompanyId(')
        ->and($component)->toContain('revision !== this.loadRevision', 'clearFilterState()')
        ->not->toContain('companyId', 'defaultCompanyApplied')
        ->and($template)->not->toContain('attribution-company-options', 'legal-entity', 'Establish company');
});

it('uses a bounded authorized Sales Profile selector for attribution corrections', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-attribution-operations/sales-attribution-operations.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-attribution-operations/sales-attribution-operations.component.html'));

    expect($routes)->toContain("Route::get('attribution-profile-options', [SalesBookingAttributionController::class, 'profileOptions'])")
        ->and($controller)->toContain("'purpose' => ['required', Rule::in(['acquisition', 'collection'])]", "'max:50'")
        ->not->toContain("'company_id' => ['nullable'", "'cross_company'", 'CompanyIdScope')
        ->not->toContain("'profiles' =>")
        ->and($component)->toContain('profileOptionQuery', 'profileOptionsDateChanged()')
        ->not->toContain('eligibleProfiles(')
        ->and($template)->toContain('endpoint="/sales/attribution-profile-options"', "[required]=\"actionKind !== 'handler'\"")
        ->not->toContain('eligibleProfiles(row.company_id)');
});
