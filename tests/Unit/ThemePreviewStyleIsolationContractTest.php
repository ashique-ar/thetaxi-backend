<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->layout = file_get_contents($projectRoot . '/resources/views/layouts/app.blade.php');
    $this->manifest = file_get_contents($projectRoot . '/config/website_themes.php');
    $this->theme01 = file_get_contents($projectRoot . '/public/assets/css/theme-01.css');
    $this->theme02 = file_get_contents($projectRoot . '/public/assets/css/theme-02-tw.css');
    $this->theme03 = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
    $this->theme03Pages = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/pages.css');
    $this->theme04 = file_get_contents($projectRoot . '/public/assets/css/themes/theme-04/theme-04.css');
    $this->theme04Pages = file_get_contents($projectRoot . '/public/assets/css/themes/theme-04/pages.css');
    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
});

it('loads shared booking primitives before the active theme presentation', function () {
    $bookingPosition = strpos($this->layout, "assetVersion('assets/css/booking-form.css')");
    $packagePosition = strpos($this->layout, "assetVersion('assets/css/package-buttons.css')");
    $themeBranchPosition = strpos($this->layout, '<!-- Active-theme presentation must follow shared booking primitives. -->');

    expect($bookingPosition)->toBeLessThan($themeBranchPosition)
        ->and($packagePosition)->toBeLessThan($themeBranchPosition)
        ->and($this->layout)->toContain('data-theme-presentation');
});

it('keeps a distinct booking form presentation contract for all four themes', function () {
    expect($this->theme01)
        ->toContain('.filter-wrapper .filter-input-wrap')
        ->toContain('border-radius: 16px !important')
        ->and($this->theme02)
        ->toContain('.t2-filter-wrapper .filter-input-wrap')
        ->toContain('backdrop-filter: blur(24px)')
        ->and($this->theme03)
        ->toContain('.t3-journey-desk .filter-input-wrap')
        ->toContain('border-radius: 0 !important')
        ->and($this->theme04)
        ->toContain('.t4-booking-panel .filter-wrapper .filter-input-wrap')
        ->toContain('.t4-booking-panel .package-button')
        ->toContain('border-radius: 6px !important');
});

it('loads a late page isolation bundle for each preview theme', function () {
    expect($this->manifest)
        ->toContain("'theme-03'")
        ->toContain("'page_stylesheet' => 'assets/css/themes/theme-03/pages.css'")
        ->toContain("'theme-04'")
        ->toContain("'page_stylesheet' => 'assets/css/themes/theme-04/pages.css'")
        ->and($this->layout)
        ->toContain("theme_asset('page_stylesheet')");

    expect(strpos($this->layout, "theme_asset('page_stylesheet')"))
        ->toBeGreaterThan(strpos($this->layout, "@stack('styles')"));
});

it('keeps legacy homepage styles out of both preview themes without replaying whole bundles', function () {
    expect($this->layout)
        ->toContain("theme_asset('page_stylesheet') && !request()->routeIs('home')")
        ->not->toContain('data-theme-final-cascade');

    expect($this->home)
        ->toContain("@if (!is_theme('theme-03') && !is_theme('theme-04'))")
        ->toContain('.home-booking-form-section')
        ->toContain('.featured-vehicles-section');

    expect($this->theme04)
        ->toContain('.theme-page-home .t4-booking-panel+:where(')
        ->toContain('.t4-services,')
        ->toContain('padding-top: 54px');
});

it('gives both preview themes route-aware main isolation boundaries', function () {
    expect($this->layout)
        ->toContain('class="t3-site-main t3-page--{{ $themePageSlug }}"')
        ->toContain('class="t4-site-main t4-page--{{ $themePageSlug }}"')
        ->toContain('theme-page-{{ $themePageSlug }}');
});

it('corrects inherited container sizing and contains legacy overflow in both themes', function () {
    expect($this->theme03)
        ->toContain('width: min(calc(100% - (2 * var(--t3-gutter))), var(--t3-content))')
        ->toContain('overflow-x: clip')
        ->and($this->theme04)
        ->toContain('width: min(calc(100% - (2 * var(--t4-gutter))), var(--t4-content))')
        ->toContain('overflow-x: clip')
        ->toContain('body.theme-theme-04 *')
        ->toContain('box-sizing: border-box');
});

it('keeps the two page systems visually and selector-isolated', function () {
    expect($this->theme03Pages)
        ->toContain('body.theme-theme-03')
        ->toContain('.t3-site-main')
        ->toContain('background: var(--t3-ink)')
        ->toContain('box-shadow: 18px 18px 0 var(--t3-stone)')
        ->not->toContain('body.theme-theme-04')
        ->not->toContain('.t4-site-main')
        ->and($this->theme04Pages)
        ->toContain('body.theme-theme-04')
        ->toContain('.t4-site-main')
        ->toContain('border-top: 4px solid var(--t4-accent)')
        ->not->toContain('body.theme-theme-03')
        ->not->toContain('.t3-site-main');
});

it('resets the legacy service hero classes inside each theme boundary', function () {
    foreach ([$this->theme03Pages, $this->theme04Pages] as $pages) {
        expect($pages)
            ->toContain('.banner-video-area')
            ->toContain('min-height: 0')
            ->toContain('border-radius: 0')
            ->toContain('.filter-wrapper')
            ->toContain('margin-top:');
    }
});

it('fully removes the released filter input wrapper presentation in both preview themes', function () {
    expect($this->theme03)
        ->toContain('.t3-journey-desk .filter-input-wrap')
        ->toContain('background: #fffaf2 !important')
        ->toContain('box-shadow: none !important')
        ->and($this->theme03Pages)
        ->toContain('.t3-site-main .filter-wrapper .filter-input-wrap')
        ->toContain('border-radius: 0 !important')
        ->and($this->theme04)
        ->toContain('.t4-booking-panel .filter-wrapper .filter-input-wrap')
        ->toContain('background: transparent !important')
        ->and($this->theme04Pages)
        ->toContain('.t4-site-main .filter-wrapper .filter-input-wrap')
        ->toContain('box-shadow: none !important');
});

it('keeps the Theme 04 hero and journey desk responsive', function () {
    expect($this->theme04)
        ->toContain('--t4-hero-height: clamp(620px, min(72svh, 42vw), 900px)')
        ->toContain('--t4-hero-height: clamp(520px, 68svh, 680px)')
        ->toContain('--t4-hero-height: clamp(520px, 75svh, 680px)')
        ->toContain('--t4-hero-overlap: calc(var(--t4-hero-height) / 6)')
        ->toContain('margin-top: calc(var(--t4-hero-overlap) * -1)')
        ->toContain('width: min(500px, 42%)')
        ->toContain('grid-template-columns: 90px minmax(0, 1fr)')
        ->toContain('min-height: 54px')
        ->toContain('min-height: 40px !important')
        ->toContain('font-size: 0.8125rem !important');
});

it('keeps functional Theme 04 booking text above the compact unreadable scale', function () {
    expect($this->theme04)
        ->toContain('font-size: 0.6875rem !important')
        ->toContain('font-size: 0.65625rem !important')
        ->toContain('font-size: 0.8125rem !important')
        ->toContain('font-size: 0.75rem !important');
});

it('keeps Theme 04 booking tabs as a vertical side rail on mobile', function () {
    expect($this->theme04)
        ->toContain('@media (max-width: 991px)')
        ->toContain('grid-template-columns: 90px minmax(0, 1fr)')
        ->toContain('display: grid !important')
        ->toContain('overflow: visible')
        ->toContain('@media (max-width: 479px)')
        ->toContain('grid-template-columns: 78px minmax(0, 1fr)')
        ->toContain('white-space: normal !important')
        ->toContain('text-overflow: clip !important');
});

it('keeps Theme 04 booking service tabs in a vertical side rail on mobile', function () {
    expect($this->theme04)
        ->toContain('@media (max-width: 991px)')
        ->toContain('grid-template-columns: 90px minmax(0, 1fr)')
        ->toContain('display: grid !important')
        ->toContain('@media (max-width: 479px)')
        ->toContain('grid-template-columns: 78px minmax(0, 1fr)')
        ->not->toContain('flex: 0 0 120px');
});

it('gives Theme 04 textarea fields independent multiline geometry', function () {
    expect($this->theme04)
        ->toContain('.booking-field:has(textarea) .single-search-box')
        ->toContain('min-height: 82px !important')
        ->toContain('.t4-booking-panel .booking-field textarea')
        ->toContain('min-height: 80px !important')
        ->toContain('overflow: auto !important')
        ->toContain('white-space: pre-wrap !important');
});

it('keeps production release gates independent and false by default', function () {
    expect($this->manifest)
        ->toContain("env('WEBSITE_THEME_03_ENABLED', false)")
        ->toContain("env('WEBSITE_THEME_04_ENABLED', false)");
});
