<?php

it('adds finality-policy soft deletes and protects rollback of deleted history', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_03_120000_add_soft_deletes_to_booking_payment_finality_policies.php'));

    expect($migration)
        ->toContain("Schema::table('booking_payment_finality_policies', fn (\$table) => \$table->softDeletes())")
        ->toContain("whereNotNull('deleted_at')->exists()")
        ->toContain('Cannot remove finality-policy soft deletes while deleted policy history exists.')
        ->toContain('dropSoftDeletes()');
});
