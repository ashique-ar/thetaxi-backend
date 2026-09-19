<?php

require_once dirname(__DIR__, 2) . '/app/Helpers/theme_helpers.php';

it('normalizes mixed image and video slides with backward-compatible fallbacks', function () {
    $slides = get_hero_slides([
        'banner_heading' => 'Journeys',
        'hero_slides' => json_encode([
            ['type' => 'image', 'desktop' => 'desktop.jpg', 'mobile' => 'mobile.jpg', 'heading' => 'Airport arrivals', 'subheading' => 'Meet and greet'],
            ['type' => 'video', 'video' => 'hero.mp4', 'poster' => 'poster.jpg'],
        ]),
    ]);

    expect($slides)->toHaveCount(2)
        ->and($slides[0]['type'])->toBe('image')
        ->and($slides[0]['mobile'])->toBe('mobile.jpg')
        ->and($slides[0]['heading'])->toBe('Airport arrivals')
        ->and($slides[0]['subheading'])->toBe('Meet and greet')
        ->and($slides[1]['type'])->toBe('video')
        ->and($slides[1]['poster'])->toBe('poster.jpg');

    expect(get_hero_slides(['banner_video' => 'legacy.mp4', 'banner_image' => 'legacy.jpg'])[0])
        ->toMatchArray(['type' => 'video', 'video' => 'legacy.mp4', 'poster' => 'legacy.jpg']);
    expect(get_hero_slides(['banner_image' => 'single.jpg'])[0])
        ->toMatchArray(['type' => 'image', 'desktop' => 'single.jpg']);
});

it('keeps theme 04 text attached to each managed slide', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/resources/views/partials/themes/theme-04/hero.blade.php');

    expect($source)->toContain("\$slide['heading']")
        ->toContain("\$slide['subheading']")
        ->toContain("\$slide['caption']")
        ->not->toContain('t4-hero-title');
});

it('carries theme 04 hero details from the slide editor to the public view', function () {
    $slide = get_hero_slides(['hero_slides' => [[
        'desktop' => 'hero.jpg',
        'eyebrow' => 'Explore Sri Lanka',
        'cta_text' => 'See our fleet',
        'cta_url' => '/vehicles',
        'location_label' => 'Sigiriya, Sri Lanka',
    ]] ])[0];

    expect($slide)->toMatchArray([
        'eyebrow' => 'Explore Sri Lanka',
        'ctaText' => 'See our fleet',
        'ctaUrl' => '/vehicles',
        'locationLabel' => 'Sigiriya, Sri Lanka',
    ]);
    expect(get_hero_slides(['hero_slides' => [[
        'desktop' => 'hero.jpg', 'cta_url' => 'javascript:alert(1)',
    ]] ])[0]['ctaUrl'])->toBe('/services');

    $root = dirname(__DIR__, 2);
    $view = file_get_contents($root . '/resources/views/partials/themes/theme-04/hero.blade.php');
    $editor = file_get_contents(dirname($root) . '/portal-thetaxi/src/app/modules/admin/system/settings/website-settings-enhanced.component.html');
    $editorForm = file_get_contents(dirname($root) . '/portal-thetaxi/src/app/modules/admin/system/settings/website-settings-enhanced.component.ts');
    foreach (['eyebrow', 'ctaText', 'ctaUrl', 'locationLabel'] as $field) {
        expect($view)->toContain("\$slide['{$field}']");
    }
    foreach (['eyebrow', 'cta_text', 'cta_url', 'location_label'] as $field) {
        expect($editor)->toContain('formControlName="' . $field . '"');
        expect($editorForm)->toContain($field . ': [');
    }
    expect($editor)->toContain('formControlName="hero_booking_note"')
        ->and($editorForm)->toContain("hero_booking_note: ['']")
        ->and(file_get_contents($root . '/app/Services/WebsiteSettingsService.php'))->toContain("'hero_booking_note'")
        ->and(file_get_contents($root . '/resources/views/partials/themes/theme-04/booking-form.blade.php'))->toContain("\$settings['hero_booking_note']");
});

it('renders the same shared slider and media partial in all four theme heroes', function () {
    $root = dirname(__DIR__, 2);
    foreach (['partials/hero.blade.php', 'partials/themes/theme-02/hero.blade.php',
        'partials/themes/theme-03/hero.blade.php', 'partials/themes/theme-04/hero.blade.php'] as $view) {
        $source = file_get_contents($root . '/resources/views/' . $view);
        expect($source)->toContain('get_hero_slides($settings)')
            ->toContain('shared-hero-swiper')
            ->toContain("@include('partials.hero-media'");
    }

    $media = file_get_contents($root . '/resources/views/partials/hero-media.blade.php');
    expect($media)->toContain("\$slide['type'] === 'video'")
        ->toContain('muted playsinline preload="metadata"')
        ->toContain('<picture>')->toContain('<source media="(max-width: 767px)"');
});

it('uses one idempotent reduced-motion-aware slider behavior', function () {
    $script = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/custom.js');
    expect($script)->toContain('document.querySelectorAll(".shared-hero-swiper")')
        ->toContain('slider.dataset.heroReady')
        ->toContain('prefers-reduced-motion: reduce')
        ->toContain('syncHeroVideo')
        ->toContain('video.play()')->toContain('video.pause()');
});

it('keeps the default theme hero pagination horizontal', function () {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/theme-01.css');

    expect($css)
        ->toContain('body.theme-default .shared-hero-pagination .swiper-pagination-bullet')
        ->toContain('display: flex;')
        ->toContain('width: 10px;')
        ->toContain('height: 10px;')
        ->toContain('width: 28px;');
});

it('keeps theme 04 hero pagination horizontal and theme scoped', function () {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/themes/theme-04/theme-04.css');

    expect($css)
        ->toContain('body.theme-theme-04 .shared-hero-pagination .swiper-pagination-bullet')
        ->toContain('display: flex;')
        ->toContain('width: 10px;')
        ->toContain('height: 10px;')
        ->toContain('width: 28px;')
        ->toContain('background: var(--t4-accent);');
});
