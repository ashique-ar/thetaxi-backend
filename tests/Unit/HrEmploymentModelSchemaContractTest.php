<?php

it('aligns employment models with inherited soft-delete and user-tracking behavior', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_31_173000_align_employment_models_with_base_model.php'));

    expect($migration)
        ->toContain("Schema::table('hr_employment_spells'")
        ->toContain("Schema::table('hr_employment_assignments'")
        ->toContain("foreignUuid('created_user_id')")
        ->toContain("foreignUuid('updated_user_id')")
        ->toContain('softDeletes()');
});
