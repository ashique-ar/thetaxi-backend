<?php

it('only blocks duplicate active website setting keys before dropping company scope', function () {
    $preflightPath = database_path('migrations/2026_10_30_000014_preflight_website_settings_before_scope_removal.php');
    $preflight = file_get_contents($preflightPath);
    $migration = file_get_contents(database_path('migrations/2026_10_31_000029_remove_website_settings_tenant_company.php'));
    $orderedMigrations = glob(database_path('migrations/2026_10_30_*.php'));
    sort($orderedMigrations);
    $preflightPosition = array_search($preflightPath, $orderedMigrations, true);
    $removalPosition = array_search(database_path('migrations/2026_10_30_000015_make_website_settings_installation_wide.php'), $orderedMigrations, true);
    $nullTypeGuard = strpos($migration, "->whereNotNull('type')");
    $duplicateCheck = strpos($migration, "->select('type')->groupBy('type')->havingRaw('COUNT(*) > 1')");
    $companyColumnDrop = strpos($migration, "dropColumn('company_id')");

    expect($preflight)
        ->toContain("->whereNotNull('type')", "->select('type')->groupBy('type')->havingRaw('COUNT(*) > 1')")
        ->toContain('Reconcile duplicate active website setting types before migration 2026_10_30_000015')
        ->and($preflightPosition)->not->toBeFalse()
        ->and($removalPosition)->not->toBeFalse()
        ->and($preflightPosition)->toBeLessThan($removalPosition)
        ->and($nullTypeGuard)->not->toBeFalse()
        ->and($duplicateCheck)->not->toBeFalse()
        ->and($companyColumnDrop)->not->toBeFalse()
        ->and($migration)->toContain(
            '$this->relationExists($tableName, $name)',
            'private function relationExists(string $tableName, string $name)',
        )
        ->and($nullTypeGuard)->toBeLessThan($duplicateCheck)
        ->and($duplicateCheck)->toBeLessThan($companyColumnDrop);
});
