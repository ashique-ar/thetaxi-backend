<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->offer = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/offer-slider.blade.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
    $this->customScript = file_get_contents($projectRoot . '/public/assets/js/custom.js');
    $this->settingsComposer = file_get_contents($projectRoot . '/app/Http/ViewComposers/SettingsViewComposer.php');
    $this->settingsService = file_get_contents($projectRoot . '/app/Services/WebsiteSettingsService.php');
});

it('selects a Theme 03 offer presenter while retaining released-theme markup', function () {
    expect($this->home)
        ->toContain("@if (\$settings['offer_slider_img_1'])")
        ->toContain("@include('partials.themes.theme-03.offer-slider')")
        ->toContain('<div class="home4-offer-slider-section mb-100">')
        ->toContain('class="swiper home4-offer-slider"')
        ->toContain('class="swiper-pagination2 paginations two"');
});

it('preserves the exact two configured campaign images and link fallbacks', function () {
    expect(substr_count($this->offer, 'class="swiper-slide"'))->toBe(2)
        ->and($this->offer)
        ->toContain("\$settings['offer_slider_link_1'] ?? route('booking.search')")
        ->toContain("\$settings['offer_slider_link_2'] ?? route('booking.search')")
        ->toContain("s3_asset(\$settings['offer_slider_img_1'] ?? 'assets/img/home4/home4-offer-slider-img1.jpg')")
        ->toContain("s3_asset(\$settings['offer_slider_img_2'] ?? 'assets/img/home4/home4-offer-slider-img2.jpg')")
        ->toContain('class="swiper home4-offer-slider"')
        ->toContain('class="t3-campaigns__pagination swiper-pagination2 paginations two"')
        ->not->toContain('<form')
        ->not->toContain('<script');
});

it('keeps offer media owned by Website Settings and dimensioned below the fold', function () {
    expect($this->settingsComposer)
        ->toContain("'offer_slider_img_1'")
        ->toContain("'offer_slider_img_2'")
        ->and($this->settingsService)
        ->toContain("'offer_slider_img_1'")
        ->toContain("'offer_slider_img_2'")
        ->and($this->offer)
        ->toContain('width="1600"')
        ->toContain('height="620"')
        ->toContain('loading="lazy"')
        ->toContain('alt=""');
});

it('retains the shared Swiper autoplay and pagination behavior', function () {
    $initializerPosition = strpos($this->customScript, 'new Swiper(".home4-offer-slider"');

    expect($initializerPosition)->not->toBeFalse()
        ->and(substr($this->customScript, $initializerPosition, 430))
        ->toContain('slidesPerView: 1')
        ->toContain('speed: 1500')
        ->toContain('autoplay:')
        ->toContain('delay: 2500')
        ->toContain('disableOnInteraction: false')
        ->toContain('el: ".swiper-pagination2"')
        ->toContain('clickable: true');
});

it('provides an image-safe responsive frame scoped to Theme 03', function () {
    expect($this->styles)
        ->toContain('body.theme-theme-03 .t3-campaigns')
        ->toContain('body.theme-theme-03 .t3-campaigns__frame')
        ->toContain('body.theme-theme-03 .t3-campaigns .swiper-slide > a')
        ->toContain('aspect-ratio: 80 / 31')
        ->toContain('object-fit: contain')
        ->toContain('body.theme-theme-03 .t3-campaigns__action')
        ->toContain('body.theme-theme-03 .t3-campaigns__pagination.swiper-pagination2')
        ->toContain('@media (max-width: 767px)');
});

