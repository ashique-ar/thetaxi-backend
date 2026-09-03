<?php

it('normalizes mixed image and video slides with backward-compatible fallbacks', function () {
    $slides = get_hero_slides([
        'banner_heading' => 'Journeys',
        'hero_slides' => json_encode([
            ['type' => 'image', 'desktop' => 'desktop.jpg', 'mobile' => 'mobile.jpg'],
            ['type' => 'video', 'video' => 'hero.mp4', 'poster' => 'poster.jpg'],
        ]),
    ]);

    expect($slides)->toHaveCount(2)
        ->and($slides[0]['type'])->toBe('image')
        ->and($slides[0]['mobile'])->toBe('mobile.jpg')
        ->and($slides[1]['type'])->toBe('video')
        ->and($slides[1]['poster'])->toBe('poster.jpg');

    expect(get_hero_slides(['banner_video' => 'legacy.mp4', 'banner_image' => 'legacy.jpg'])[0])
        ->toMatchArray(['type' => 'video', 'video' => 'legacy.mp4', 'poster' => 'legacy.jpg']);
    expect(get_hero_slides(['banner_image' => 'single.jpg'])[0])
        ->toMatchArray(['type' => 'image', 'desktop' => 'single.jpg']);
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
    $script = file_get_contents(public_path('assets/js/custom.js'));
    expect($script)->toContain('document.querySelectorAll(".shared-hero-swiper")')
        ->toContain('slider.dataset.heroReady')
        ->toContain('prefers-reduced-motion: reduce')
        ->toContain('syncHeroVideo')
        ->toContain('video.play()')->toContain('video.pause()');
});

