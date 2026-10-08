<?php

it('keeps requisition retry keys tenant-unique and refuses rollback after use', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_05_120000_add_idempotency_to_hr_job_requisitions.php'));

    expect($migration)
        ->toContain("unique(['company_id', 'idempotency_key']")
        ->toContain('CREATE UNIQUE INDEX '."'.self::INDEX.' ON hr_job_requisitions (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL")
        ->toContain("whereNotNull('idempotency_key')->orWhereNotNull('request_payload_checksum')->exists()")
        ->toContain('Cannot remove requisition idempotency evidence while requests reference it.');
});
