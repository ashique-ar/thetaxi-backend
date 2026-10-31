<?php

it('seeds Sri Lanka attendance defaults across the installation without company scope', function () {
    $seeder = file_get_contents(database_path('seeders/SriLankaAttendanceDefaultsSeeder.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_31_000018_make_attendance_configuration_installation_unique.php'));

    expect($seeder)
        ->not->toContain('companies', 'company_id', 'is_default')
        ->toContain("whereNull('employment_ended_at')")
        ->and($migration)
        ->toContain("groupBy('code', 'effective_from')")
        ->toContain("['code', 'effective_from']")
        ->toContain('Reconcile duplicate installation-wide');
});
