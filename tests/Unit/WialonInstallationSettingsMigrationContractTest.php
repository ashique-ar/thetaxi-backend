<?php

it('identifies conflicting legacy Wialon rows without exposing tokens', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_30_000076_remove_legacy_wialon_company_integrations.php'));

    expect($migration)
        ->toContain("'company_id' => \$row->company_id")
        ->toContain("'token_fingerprint' => substr(hash('sha256', decrypt(\$row->token)), 0, 12)")
        ->toContain('json_encode($conflicts, JSON_THROW_ON_ERROR)')
        ->not->toContain("'token' => \$row->token");
});
