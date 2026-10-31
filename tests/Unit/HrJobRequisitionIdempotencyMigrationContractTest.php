<?php

it('keeps the historical requisition migration intact and enforces installation-wide retry keys', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_05_120000_add_idempotency_to_hr_job_requisitions.php'));
    $cleanup = file_get_contents(database_path('migrations/2026_10_30_000035_remove_recruitment_tenant_company.php'));
    $global = file_get_contents(database_path('migrations/2026_10_31_000028_enforce_installation_wide_hr_idempotency.php'));

    expect($migration)
        ->toContain("unique(['company_id', 'idempotency_key']")
        ->toContain('CREATE UNIQUE INDEX '."'.self::INDEX.' ON hr_job_requisitions (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL")
        ->toContain("whereNotNull('idempotency_key')->orWhereNotNull('request_payload_checksum')->exists()")
        ->toContain('Cannot remove requisition idempotency evidence while requests reference it.');
    expect($cleanup)->toContain("dropColumn('company_id')");
    expect($global)->toContain("'hr_job_requisitions' => [['idempotency_key']]")
        ->toContain('Cannot enforce installation-wide idempotency')
        ->toContain("\$table->unique(\$columns, \$name)");
});
