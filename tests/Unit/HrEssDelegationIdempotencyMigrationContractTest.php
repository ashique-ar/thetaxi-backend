<?php

it('adds reversible company-scoped idempotency for delegation requests without dropping used evidence', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_03_123000_add_idempotency_to_hr_approval_delegations.php'));

    expect($migration)
        ->toContain("uuid('idempotency_key')->nullable()", "char('request_payload_checksum', 64)->nullable()")
        ->toContain('hr_delegations_company_idempotency_unique', 'idempotency_key IS NOT NULL')
        ->toContain("whereNotNull('idempotency_key')->exists()", 'Cannot roll back delegation idempotency')
        ->toContain("dropColumn(['idempotency_key', 'request_payload_checksum'])");
});

it('recovers concurrent delegation idempotency collisions as replay or conflict', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/EssController.php'));

    expect($controller)
        ->toContain('catch (QueryException $e)', 'where(\'idempotency_key\', $d[\'idempotency_key\'])->first()')
        ->toContain('if (! $existing) throw $e;', 'hash_equals((string) $existing->request_payload_checksum, $checksum)');
});
