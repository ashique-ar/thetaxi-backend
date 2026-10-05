<?php

it('preserves selected Staff approval evidence on rollback', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_03_121000_add_selected_staff_approval_evidence_to_hr_delegations.php'));

    expect($migration)->toContain("foreignUuid('approved_by_staff_id')->nullable()->constrained('staff')->restrictOnDelete()", "whereNotNull('approved_by_staff_id')->exists()", 'Cannot roll back selected Staff approval evidence')
        ->and($migration)->toContain("dropConstrainedForeignId('approved_by_staff_id')");
});
