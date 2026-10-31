<?php

it('does not resolve or require a default company for commission configuration', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionConfigurationController.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_30_000079_remove_commission_configuration_tenant_company.php'));

    expect($controller)->not->toContain('company_id', 'resolveCompanyId', 'assertCompanyScope', 'activeDefaultCompany')
        ->and($migration)->toContain("dropColumn('company_id')", "where('scope_type', 'company')->update(['scope_type' => 'installation'])")
        ->not->toContain('company_id_default');
});
