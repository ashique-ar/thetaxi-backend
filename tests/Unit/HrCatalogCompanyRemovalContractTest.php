<?php

it('keeps HR default seeders installation-wide and guards catalog code collisions', function () {
    $organization = file_get_contents(database_path('seeders/HrOrganizationDefaultsSeeder.php'));
    $payroll = file_get_contents(database_path('seeders/HrPayrollDefaultsSeeder.php'));
    $attendance = file_get_contents(database_path('seeders/HrAttendanceDeviceDefaultsSeeder.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_31_000019_enforce_installation_wide_hr_catalog_codes.php'));

    expect($organization.$payroll.$attendance)
        ->not->toContain('company_id', 'is_default', "DB::table('companies')")
        ->and($migration)
        ->toContain("groupBy('code')")
        ->toContain('Reconcile duplicate installation-wide codes')
        ->toContain('$table->unique(\'code\'');
});
