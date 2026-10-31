<?php

it('governs payment finality with installation-wide policies and permission checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentFinalityController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain('Finality-policy creator cannot approve the same version.', 'An approved finality policy already overlaps this effective period.')
        ->not->toContain('company_id', 'resolveCompanyId(', 'activeDefaultCompany')
        ->and($routes)
        ->toContain("permission:sales.payment-finality.manage", "permission:sales.payment-finality.approve", "permission:sales.payment-finality.transition");
});
