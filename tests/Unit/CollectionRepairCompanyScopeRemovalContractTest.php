<?php

it('archives collection repair evidence without company tenancy fields', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_30_000080_archive_collection_repairs_without_company_scope.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($migration)
        ->toContain("'sales_collection_company_repairs'", "'sales_collection_company_repair_rollbacks'", "'sales_collection_company_repair_rollback_items'", "'sales_collection_repairs'", "'before_company_id'", "'after_company_id'", 'dropColumn($presentColumns)')
        ->not->toContain('dropIfExists')
        ->and($routes)->not->toContain('SalesCollectionCompanyRepairController', 'collection-company-repairs');
});
