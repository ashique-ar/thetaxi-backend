<?php

it('keeps lifecycle template retry keys tenant-unique and refuses rollback after use', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_05_120200_add_idempotency_to_hr_lifecycle_templates.php'));

    expect($migration)
        ->toContain("unique(['company_id', 'idempotency_key']")
        ->toContain('CREATE UNIQUE INDEX ')
        ->toContain('ON hr_lifecycle_templates (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL')
        ->toContain("whereNotNull('idempotency_key')->orWhereNotNull('request_payload_checksum')->exists()")
        ->toContain('Cannot remove lifecycle template idempotency evidence while requests reference it.');
});
