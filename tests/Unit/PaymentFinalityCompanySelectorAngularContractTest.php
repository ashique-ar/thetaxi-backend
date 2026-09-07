<?php

it('uses a bounded legal-entity selector and never renders a company UUID fallback', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentFinalityController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-payment-finality/sales-payment-finality.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-payment-finality/sales-payment-finality.component.html'));

    expect($routes)->toContain("Route::get('payment-finality-company-options', [PaymentFinalityController::class, 'companyOptions'])")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("whereIn('id', \$this->actorCompanyIds(\$request))")
        ->toContain("'companies.name as company_name'")
        ->and($component)->toContain('UiManagedRecordSelectComponent')
        ->and($template)->toContain('endpoint="/sales/payment-finality-company-options"', '{{companyName(row)}}')
        ->not->toContain('companies()', 'companyName(row.company_id)')
        ->and($component)->toContain("row.company_name||'Unavailable legal entity'")
        ->not->toContain('?.name||id', 'paymentFinalityContext()');
});
