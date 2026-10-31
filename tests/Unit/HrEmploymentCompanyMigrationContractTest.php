<?php

it('guards global employment type codes before removing HR company keys', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_30_000031_remove_employment_spell_tenant_company.php'));
    $collisionGuard = strpos($migration, "Cannot make HR employment type codes installation-wide");
    $activityMutation = strpos($migration, "DB::table('activity_log')");

    expect($collisionGuard)->not->toBeFalse()
        ->and($activityMutation)->not->toBeFalse()
        ->and($collisionGuard)->toBeLessThan($activityMutation)
        ->and($migration)->toContain('TenantIndexDropper::drop($tableName, $index)', "unique('code', 'hr_employment_types_code_unique')");
});
