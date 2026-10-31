<?php

it('guards asset catalog global keys before removing company keys', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_30_000039_remove_asset_catalog_tenant_company.php'));
    $collisionGuard = strpos($migration, 'Cannot make {$tableName}.{$column} installation-wide');
    $companyColumnDrop = strpos($migration, "dropColumn('company_id')");

    expect($collisionGuard)->not->toBeFalse()
        ->and($companyColumnDrop)->not->toBeFalse()
        ->and($collisionGuard)->toBeLessThan($companyColumnDrop)
        ->and($migration)->toContain(
            'TenantIndexDropper::drop($assetTable, $index)',
            '$this->relationExists($tableName, $name)',
            'private function relationExists(string $tableName, string $name)',
            'Schema::table($assetTable, function (Blueprint $table): void',
            'use ($columns, $name, $unique)',
            'INDEX IF NOT EXISTS',
            "'hr_asset_types_installation_code_unique', true",
            "'hr_asset_items_installation_tag_unique', true",
        );
});
