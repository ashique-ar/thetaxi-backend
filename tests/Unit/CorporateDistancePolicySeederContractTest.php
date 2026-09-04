<?php


it('keeps pilot policy seeding opt-in and disabled', function () {
    $source = file_get_contents(database_path('seeders/CorporateDistancePricingPolicySeeder.php'));

    expect($source)
        ->toContain("env('CORPORATE_DISTANCE_POLICY_PILOT_ID')")
        ->toContain('if (! $corporateId)')
        ->toContain("'default_service_mode' => 'disabled'")
        ->toContain("'application_mode' => 'disabled'");

    $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
    expect($databaseSeeder)->not->toContain('CorporateDistancePricingPolicySeeder::class');
});
