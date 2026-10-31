<?php

it('governs installation-wide Sales settings with maker-checker approval', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPolicySettingsController.php'));
    $service = file_get_contents(app_path('Services/Sales/SalesPolicySettingsService.php'));

    expect($controller)
        ->toContain('SalesPolicySettingsService::KINDS', '$this->settings->create(', '$request->user()->id')
        ->not->toContain('company_id', 'resolveCompanyId(', 'activeDefaultCompany', 'actorCompanyIds(')
        ->and($service)
        ->toContain('The policy-setting creator cannot approve the same version.')
        ->not->toContain('company_id', 'activeDefaultCompany');
});
