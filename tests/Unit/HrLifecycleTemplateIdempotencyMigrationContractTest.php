<?php

it('keeps lifecycle template history intact and enforces installation-wide retry keys', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_05_120200_add_idempotency_to_hr_lifecycle_templates.php'));
    $cleanup = file_get_contents(database_path('migrations/2026_10_30_000036_remove_lifecycle_tenant_company.php'));
    $global = file_get_contents(database_path('migrations/2026_10_31_000028_enforce_installation_wide_hr_idempotency.php'));

    expect($migration)
        ->toContain("unique(['company_id', 'idempotency_key']")
        ->toContain('CREATE UNIQUE INDEX ')
        ->toContain('ON hr_lifecycle_templates (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL')
        ->toContain("whereNotNull('idempotency_key')->orWhereNotNull('request_payload_checksum')->exists()")
        ->toContain('Cannot remove lifecycle template idempotency evidence while requests reference it.');
    expect($cleanup)->toContain("'hr_lifecycle_templates'")->toContain("dropColumn('company_id')");
    expect($global)->toContain("'hr_lifecycle_templates' => [['code', 'version'], ['idempotency_key']]")
        ->toContain('Cannot enforce installation-wide idempotency');
});
