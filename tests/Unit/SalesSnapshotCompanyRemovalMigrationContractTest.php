<?php

it('drops only Sales snapshot tenant metadata and retains snapshot history', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_31_000016_remove_sales_snapshot_tenant_company.php'));
    $periodClose = file_get_contents(app_path('Services/Sales/SalesPeriodCloseService.php'));
    $model = file_get_contents(app_path('Models/Sales/SalesKpiSnapshot.php'));

    expect($migration)
        ->toContain("Schema::getForeignKeys('sales_kpi_snapshots')")
        ->toContain('TenantIndexDropper::drop(\'sales_kpi_snapshots\', $index)')
        ->toContain('$table->dropColumn(\'company_id\')')
        ->not->toContain("DB::table('sales_kpi_snapshots')->delete()")
        ->and($periodClose)->toContain("->max('version')")
        ->not->toContain("'company_id' => null")
        ->and($model)->not->toContain('protected $hidden = [\'company_id\']');
});
