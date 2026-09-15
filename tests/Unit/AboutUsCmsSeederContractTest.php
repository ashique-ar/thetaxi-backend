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
    $themeFour = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/themes/theme-04/pages.css');

    expect($view)
        ->toContain('font-size: clamp(1.65rem, 2.2vw, 2.25rem);')
        ->toContain('font-size: clamp(1.25rem, 1.6vw, 1.55rem);')
        ->toContain('font-size: 1.1rem;');

    expect($themeFour)
        ->toContain('.theme-page-cms-show .cms-article-title')
        ->toContain('font-size: clamp(1.65rem, 2.2vw, 2.25rem) !important;')
        ->toContain('.theme-page-cms-show .content-body h2 { font-size: clamp(1.25rem, 1.6vw, 1.55rem) !important; }')
        ->not->toContain('font-size: clamp(2.6rem, 5vw, 5.4rem)');
});

test('about media uses the existing common s3 disk', function () {
    $projectRoot = dirname(__DIR__, 2);
    $filesystems = file_get_contents($projectRoot . '/config/filesystems.php');

    expect($filesystems)
        ->toContain("'s3' => [")
        ->toContain("env('AWS_ACCESS_KEY_ID')")
        ->not->toContain("'cms_media' => [");
});

test('theme four renders the shared booking form in the cms sidebar', function () {
    $root = dirname(__DIR__, 2);
    $view = file_get_contents($root . '/resources/views/cms/show.blade.php');
    $bookingForm = file_get_contents($root . '/resources/views/components/booking-form.blade.php');
    $themeFour = file_get_contents($root . '/public/assets/css/themes/theme-04/pages.css');

    expect($view)
        ->toContain("@if (is_theme('theme-04'))")
        ->toContain("@include('components.booking-form'")
        ->toContain("@unless (is_theme('theme-04'))");

    expect($bookingForm)->toContain("@include('components.dynamic-booking-form'");
    expect(file_exists($root . '/resources/views/cms/partials/booking.blade.php'))->toBeFalse();
    expect($themeFour)->toContain('.theme-page-cms-show .cms-booking-section :where([class*="col-"], .single-search-box)');

    expect(strpos($view, "@unless (is_theme('theme-04'))"))
        ->toBeGreaterThan(strpos($view, '</aside>'));
});

test('theme four white surfaces reset inherited white text', function () {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/themes/theme-04/pages.css');

    expect($css)
        ->toContain('.cms-article-main,')
        ->toContain('.contact-form-wrap,')
        ->toContain('.checkout-form-wrapper,')
        ->toContain('color: var(--t4-text) !important;')
        ->toContain(':where(input, textarea, .form-control)::placeholder')
        ->toContain('color: var(--t4-muted) !important;');
});

test('cms category masthead and shared cards remain readable on mobile', function () {
    $root = dirname(__DIR__, 2);
    $css = file_get_contents($root . '/public/assets/css/themes/theme-04/pages.css');
    $card = file_get_contents($root . '/resources/views/components/cms-card.blade.php');
    $section = file_get_contents($root . '/resources/views/components/cms-section.blade.php');

    expect($css)
        ->toContain('.cms-header :where(.breadcrumb-item, .breadcrumb-item a, .subtitle)')
        ->toContain('.cms-header h1 { font-size: clamp(2rem, 10vw, 2.8rem); }')
        ->toContain('.travel-inspiration-page > .container { padding-inline: 0; }');

    expect($card)
        ->toContain('.cms-content-card .blog-img-wrap')
        ->toContain('aspect-ratio: 16 / 9;')
        ->toContain('.cms-content-card:hover');

    expect($section)
        ->toContain('.cms-content-section .container')
        ->toContain('font-size: clamp(1.75rem, 9vw, 2.35rem);');
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
