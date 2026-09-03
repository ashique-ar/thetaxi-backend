<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->hero = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/hero.blade.php');
    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
    $this->manifest = require $projectRoot . '/config/website_themes.php';
});

it('keeps the Theme 03 hero on the existing homepage content owners', function () {
    expect($this->hero)
        ->toContain("\$settings['banner_heading']")
        ->toContain("\$settings['banner_subheading']")
        ->toContain('get_hero_slides($settings)')
        ->toContain("\$settings['site_name']")
        ->toContain("\$settings['site_tagline']")
        ->not->toContain('theme_02_slider_images')
        ->not->toContain('banner_cta')
        ->not->toContain('components.booking-form')
        ->not->toContain('<form');
});

it('preserves the separate shared booking-form boundary after the hero', function () {
    $heroPosition = strpos($this->home, "@include(theme_partial('hero'))");
    $bookingPosition = strpos($this->home, "@include('components.booking-form')");

    expect($heroPosition)->not->toBeFalse()
        ->and($bookingPosition)->not->toBeFalse()
        ->and($bookingPosition)->toBeGreaterThan($heroPosition)
        ->and($this->home)->toContain('class="home-booking-form-section');
});

it('uses semantic shared mixed media with eager first-slide loading', function () {
    $media = file_get_contents(dirname(__DIR__, 2) . '/resources/views/partials/hero-media.blade.php');
    expect($this->hero)
        ->toContain('<section class="t3-hero" aria-labelledby="t3-hero-title">')
        ->toContain('<h1 id="t3-hero-title">')
        ->toContain('<figure class="t3-hero__visual">')
        ->toContain('shared-hero-swiper')
        ->toContain("@include('partials.hero-media'")
        ->not->toContain('<script');
    expect($media)->toContain('width="1920"')
        ->toContain('height="1080"')
        ->toContain("\$index === 0 ? 'eager' : 'lazy'")
        ->toContain('fetchpriority="high"')
        ->toContain('<video');
});

it('provides an isolated asymmetric responsive hero composition', function () {
    expect($this->styles)
        ->toContain('body.theme-theme-03 .t3-hero')
        ->toContain('body.theme-theme-03 .t3-hero__frame')
        ->toContain('grid-template-columns: minmax(360px, 0.82fr) minmax(520px, 1.18fr)')
        ->toContain('body.theme-theme-03 .t3-hero__image-frame')
        ->toContain('clip-path: polygon(8% 0')
        ->toContain('body.theme-theme-03 .home-booking-form-section')
        ->toContain('@media (max-width: 991px)')
        ->toContain('@media (max-width: 767px)');
});

it('keeps the hero mandatory while Theme 03 remains release gated', function () {
    $theme = $this->manifest['themes']['theme-03'];

    expect($theme['required_partials'])->toContain('hero')
        ->and($theme['released'])->toBeFalse();
});
