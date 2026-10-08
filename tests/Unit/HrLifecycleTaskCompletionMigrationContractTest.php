<?php

it('keeps task completion replay keys unique and protects used evidence from rollback', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_05_120300_add_idempotency_to_hr_lifecycle_task_completion.php'));

    expect($migration)
        ->toContain("unique('idempotency_key'")
        ->toContain('CREATE UNIQUE INDEX ')
        ->toContain('ON hr_lifecycle_tasks (idempotency_key) WHERE idempotency_key IS NOT NULL')
        ->toContain("whereNotNull('idempotency_key')->orWhereNotNull('completion_checksum')->exists()")
        ->toContain('Cannot remove lifecycle completion evidence while requests reference it.');
});
