<?php

it('preserves inquiry scope history while removing its obsolete company field', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_31_000032_preserve_inquiry_scope_history_without_company.php'));

    expect($migration)
        ->toContain("\$oldTable = 'inquiry_company_scope_events'")
        ->toContain("\$historyTable = 'inquiry_scope_history_events'")
        ->toContain("dropColumn('company_id')")
        ->toContain('TenantIndexDropper::drop($tableName, $index)')
        ->toContain('Schema::rename($oldTable, $historyTable)')
        ->toContain("'2026_10_30_000052_drop_inquiry_company_scope_history'")
        ->toContain('Restore inquiry_company_scope_events from backup')
        ->not->toContain('Schema::drop(');
});
