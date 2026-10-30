<?php

it('keeps payment finality configuration installation-wide in API, schema and portal', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentFinalityController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_30_000061_make_payment_finality_policy_installation_wide.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-payment-finality/sales-payment-finality.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-payment-finality/sales-payment-finality.component.html'));

    expect($routes)->not->toContain('payment-finality-company-options', 'payment-finality-context')
        ->and($controller)->not->toContain('company_id', 'actorCompanyIds', 'companyOptions')
        ->and($migration)->toContain("dropColumn('company_id')", 'overlap', 'booking_finality_installation_lookup_idx')
        ->and($component)->not->toContain('companyId', 'company_id', 'paymentFinalityCompanyOptions')
        ->and($template)->not->toContain('Company</th>', 'Select a company', 'payment-finality-company-options');
});
