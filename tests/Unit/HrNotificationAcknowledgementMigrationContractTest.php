<?php

it('preserves notification acknowledgement lease evidence on rollback', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_03_122000_add_notification_acknowledgement_lease_evidence.php'));

    expect($migration)
        ->toContain("char('lease_token_hash', 64)->nullable()")
        ->toContain("whereNotNull('lease_token_hash')->exists()")
        ->toContain('Cannot roll back notification acknowledgement lease evidence')
        ->toContain("dropIndex('hr_notification_ack_lease_hash_index')")
        ->toContain("dropColumn('lease_token_hash')");
});
