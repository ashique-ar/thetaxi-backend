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
        ->toContain("WebsiteSetting::setValue('about_page_slug', 'about-thetaxi')")
        ->toContain('!$content->updated_user_id');

    expect(file_get_contents(dirname(__DIR__, 2) . '/database/seeders/DatabaseSeeder.php'))
        ->not->toContain('TheTaxiAboutCmsSeeder::class');
});

test('about redirects to the company configured cms page', function () {
    $root = dirname(__DIR__, 2);
    $controller = file_get_contents($root . '/app/Http/Controllers/Website/CmsController.php');
    $casonsSeeder = file_get_contents($root . '/database/seeders/AboutUsCmsSeeder.php');

    expect($controller)
        ->toContain("\$contentTypeSlug === 'about'")
        ->toContain("get('about_page_slug')")
        ->toContain("redirect()->route('cms.show'");

    expect($casonsSeeder)
        ->toContain("WebsiteSetting::setValue('about_page_slug', 'who-we-are')");
});
