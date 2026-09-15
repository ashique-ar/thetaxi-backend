<?php

test('the taxi about content has its own safe cms seeder', function () {
    $seeder = file_get_contents(dirname(__DIR__, 2) . '/database/seeders/TheTaxiAboutCmsSeeder.php');

    expect($seeder)
        ->toContain("class TheTaxiAboutCmsSeeder")
        ->toContain("['slug' => 'about-thetaxi']")
        ->toContain('Welcome to TheTaxi')
        ->toContain('2011 – Launched')
        ->toContain('2026 – TheTaxi rebrand')
        ->toContain('Why Travel with TheTaxi?')
        ->toContain('!$content->updated_user_id');

    expect(file_get_contents(dirname(__DIR__, 2) . '/database/seeders/DatabaseSeeder.php'))
        ->not->toContain('TheTaxiAboutCmsSeeder::class');
});
