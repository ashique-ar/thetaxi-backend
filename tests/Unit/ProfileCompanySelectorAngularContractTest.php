<?php

it('uses a bounded effective-Sales-scope company selector for Profile administration', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-profile-administration/sales-profile-administration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-profile-administration/sales-profile-administration.component.html'));

    expect($routes)->toContain("Route::get('profile-company-options', [SalesProfileController::class, 'companyOptions'])")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']", "if (empty(\$data['company_id']))")
        ->toContain("whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())")
        ->and($component)->toContain('UiManagedRecordSelectComponent', 'revision !== this.contextRevision', 'clearScopedState()')
        ->not->toContain('companies = signal', 'response.data?.companies')
        ->and($template)->toContain('endpoint="/sales/profile-company-options"', '*ngIf="!companyId"', '*ngIf="companyId"')
        ->not->toContain('*ngFor="let row of companies()"');
});
