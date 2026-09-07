<?php

it('uses a bounded effective-Sales-scope company selector for attribution operations', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-attribution-operations/sales-attribution-operations.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-attribution-operations/sales-attribution-operations.component.html'));

    expect($routes)->toContain("Route::get('attribution-company-options', [SalesBookingAttributionController::class, 'companyOptions'])")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']", "whereNull('deleted_at')")
        ->and($component)->toContain('UiManagedRecordSelectComponent', 'revision !== this.loadRevision', 'clearFilterState()')
        ->not->toContain('companies = signal', 'response.context?.data?.companies')
        ->and($template)->toContain('endpoint="/sales/attribution-company-options"', 'All permitted entities')
        ->not->toContain('*ngFor="let company of companies()"');
});
