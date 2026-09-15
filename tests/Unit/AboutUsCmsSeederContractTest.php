<?php

test('about pages are seeded into the existing cms and public route', function () {
    $projectRoot = dirname(__DIR__, 2);
    $seeder = file_get_contents($projectRoot . '/database/seeders/AboutUsCmsSeeder.php');
    $routes = file_get_contents($projectRoot . '/routes/web.php');

    expect($seeder)
        ->toContain("['slug' => 'about']")
        ->toContain("'slug' => 'who-we-are'")
        ->toContain("'slug' => 'recommendations'")
        ->toContain("'slug' => 'trademarks'")
        ->toContain("'slug' => 'careers'")
        ->toContain("'slug' => 'chairman-story'")
        ->toContain('Casons%20Trademark%20-%20New.png')
        ->toContain('Casons-From-Rs-20-Car-Rental-Empire.wav')
        ->toContain('British High Commission</a>')
        ->toContain('Current Vacancies</h2>')
        ->toContain("Storage::disk('s3')")
        ->toContain("'cms/about/casons-trademark-new.png'")
        ->toContain("'cms/about/casons-from-rs-20-car-rental-empire.wav'")
        ->toContain("'cms/about/recommendations/british-high-commission'")
        ->toContain("'cms/about/recommendations/kiss-fm'")
        ->toContain("'download_url' => 'https://drive.google.com/uc?export=download")
        ->toContain('Http::retry(3, 500)')
        ->toContain("'/resources/' . ltrim(\$path, '/')")
        ->not->toContain("url('resources/'")
        ->not->toContain('$disk->url($path)')
        ->toContain('CmsContent::firstOrNew')
        ->toContain('!$content->updated_user_id')
        ->toContain('NavigationMenu::firstOrCreate');

    expect($routes)
        ->toContain("Route::get('/about', [CmsController::class, 'index'])")
        ->toContain("->defaults('contentType', 'about')")
        ->toContain("Route::redirect('/about-us', '/about', 301)")
        ->toContain("->name('resources.assets')");
});

test('the shared asset resolver preserves domain independent masked paths', function () {
    $helper = file_get_contents(dirname(__DIR__, 2) . '/app/Helpers/Helper.php');

    expect($helper)
        ->toContain("str_starts_with(\$path, '/resources/')")
        ->toContain('return $path;');
});

test('about article media is constrained without changing other cms types', function () {
    $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/cms/show.blade.php');

    expect($view)
        ->toContain("@if (\$contentType->slug === 'about')")
        ->toContain('max-width: min(100%, 520px)')
        ->toContain('max-height: 360px;');
});

test('cms article headings use the compact shared scale', function () {
    $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/cms/show.blade.php');

    expect($view)
        ->toContain('font-size: clamp(1.65rem, 2.2vw, 2.25rem);')
        ->toContain('font-size: clamp(1.25rem, 1.6vw, 1.55rem);')
        ->toContain('font-size: 1.1rem;');
});

test('about media uses the existing common s3 disk', function () {
    $projectRoot = dirname(__DIR__, 2);
    $filesystems = file_get_contents($projectRoot . '/config/filesystems.php');

    expect($filesystems)
        ->toContain("'s3' => [")
        ->toContain("env('AWS_ACCESS_KEY_ID')")
        ->not->toContain("'cms_media' => [");
});

test('every external about content link is migrated by the seeder', function () {
    $seeder = new \Database\Seeders\AboutUsCmsSeeder();
    $reflection = new ReflectionClass($seeder);
    $pages = $reflection->getMethod('pages')->invoke($seeder);
    $media = $reflection->getMethod('media')->invoke($seeder);
    $externalUrls = [];

    foreach ($pages as $page) {
        preg_match_all('/(?:href|src)="(https:[^"]+)"/', $page['body'], $matches);
        $externalUrls = array_merge($externalUrls, $matches[1]);
    }

    expect(array_values(array_diff(array_unique($externalUrls), array_keys($media))))->toBe([]);
});
