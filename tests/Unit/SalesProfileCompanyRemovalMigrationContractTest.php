<?php

it('removes Sales Profile company tenancy only after preserving global Sales code uniqueness', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_31_000015_remove_sales_profile_tenant_company.php'));
    $profile = file_get_contents(app_path('Models/Sales/SalesProfile.php'));
    $performance = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($migration)
        ->toContain("select('sales_code')->groupBy('sales_code')")
        ->toContain('$table->dropColumn(\'company_id\')')
        ->toContain('$table->unique(\'sales_code\', \'sales_profiles_installation_sales_code_unique\')')
        ->and($profile)->not->toContain("'company_id'")
        ->and($performance)
        ->toContain('targetDraftRequestMatchesPersisted($existing, $requestEvidence)')
        ->not->toContain('$profile->company_id');
});
