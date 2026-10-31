<?php

it('renames inquiry scope history before the legacy migration can drop it', function () {
    $preservationPath = database_path('migrations/2026_10_30_000051_500000_preserve_inquiry_scope_history_before_legacy_drop.php');
    $destructivePath = database_path('migrations/2026_10_30_000052_drop_inquiry_company_scope_history.php');
    $migration = file_get_contents($preservationPath);
    $destructiveMigration = file_get_contents($destructivePath);

    expect(strcmp(basename($preservationPath), basename($destructivePath)))->toBeLessThan(0)
        ->and($migration)
        ->toContain('Schema::rename($oldTable, $historyTable)')
        ->not->toContain('Schema::drop');

    expect($destructiveMigration)->toContain("Schema::dropIfExists('inquiry_company_scope_events')");
});
