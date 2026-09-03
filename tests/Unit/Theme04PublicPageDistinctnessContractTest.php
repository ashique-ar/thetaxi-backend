<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->projectRoot = $projectRoot;
    $this->layout = file_get_contents($projectRoot . '/resources/views/layouts/app.blade.php');
    $this->manifest = file_get_contents($projectRoot . '/config/website_themes.php');
    $this->pages = file_get_contents($projectRoot . '/public/assets/css/themes/theme-04/pages.css');
    $this->homeStyles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-04/theme-04.css');
    $this->webxpay = file_get_contents($projectRoot . '/resources/views/checkout/webxpay-redirect.blade.php');
});

it('gives the standalone payment redirect a Theme 04 state without changing its form contract', function () {
    expect($this->webxpay)
        ->toContain('<body class="theme-{{ get_active_theme() }}">')
        ->toContain('body.theme-theme-04 .container')
        ->toContain('body.theme-theme-04 .spinner')
        ->toContain('id="webxpay-form"')
        ->toContain('action="{{ $payment_url }}"')
        ->toContain('name="payment"')
        ->toContain('name="secret_key"')
        ->toContain("document.getElementById('webxpay-form').submit()")
        ->toContain('body.theme-theme-03 .container')
        ->toContain('body.theme-theme-04 .container');
});

it('gives Theme 04 a route-aware page shell and late page override bundle', function () {
    expect($this->layout)
        ->toContain("\$themeRouteName = request()->route()?->getName() ?? 'unrouted'")
        ->toContain('theme-page-{{ $themePageSlug }}')
        ->toContain('data-theme-page="{{ $themePageSlug }}"')
        ->toContain('class="t4-site-main t4-page--{{ $themePageSlug }}"')
        ->toContain("theme_asset('page_stylesheet')")
        ->and($this->manifest)
        ->toContain("'page_stylesheet' => 'assets/css/themes/theme-04/pages.css'");

    expect(strpos($this->layout, "theme_asset('page_stylesheet')"))
        ->toBeGreaterThan(strpos($this->layout, "@stack('styles')"));
});

it('owns every reachable public page family with explicit Theme 04 styling', function () {
    foreach ([
        '.theme-page-about',
        '.theme-page-contact',
        '.theme-page-inquiry',
        '.theme-page-faq',
        '.theme-page-cms-index',
        '.theme-page-cms-show',
        '.theme-page-cms-search',
        '.theme-page-cms-featured',
        '.theme-page-search',
        '.theme-page-vehicle-details',
        '.theme-page-point-to-point',
        '.theme-page-corporate-transfers',
        '.theme-page-inquiry-services-show',
        '.theme-page-rate-chart',
        '.theme-page-checkout',
        '.theme-page-checkout-success',
        '.theme-page-checkout-payment-resume',
        '.theme-page-booking-status',
        '.theme-page-checkout-webxpay-callback',
    ] as $pageFamily) {
        expect($this->pages)->toContain($pageFamily);
    }
});

it('covers the shared component families without copying their business behavior', function () {
    expect($this->pages)
        ->toContain('.breadcrumb-section')
        ->toContain('.section-title')
        ->toContain('.accordion-button')
        ->toContain('.contact-form')
        ->toContain('.enhanced-blog-card')
        ->toContain('.cms-article-main')
        ->toContain('.search-booking-panel')
        ->toContain('.vehicle-card')
        ->toContain('.booking-form-shell')
        ->toContain('.inquiry-form-card')
        ->toContain('.rate-chart-card')
        ->toContain('.checkout-form-wrapper')
        ->toContain('.checkout-summary')
        ->not->toContain('<script')
        ->not->toContain('body.theme-theme-03')
        ->not->toContain('body.theme-theme-02');
});

it('keeps every active Laravel public view inside the route-aware layout contract', function () {
    foreach ([
        'home.blade.php',
        'about.blade.php',
        'contact.blade.php',
        'faq.blade.php',
        'point-to-point.blade.php',
        'corporate-transfers.blade.php',
        'rate-chart.blade.php',
        'search.blade.php',
        'vehicle-details.blade.php',
        'checkout.blade.php',
        'cms/index.blade.php',
        'cms/show.blade.php',
        'cms/search.blade.php',
        'cms/featured.blade.php',
        'inquiry/service-page.blade.php',
        'booking/status.blade.php',
        'checkout/success.blade.php',
        'checkout/payment-resume.blade.php',
        'checkout/callback-error.blade.php',
    ] as $view) {
        $source = file_get_contents($this->projectRoot . '/resources/views/' . $view);
        expect($source)->toContain("@extends('layouts.app')");
    }
});

it('pins the reference visual vocabulary and responsive behavior independently of Theme 03', function () {
    expect($this->pages)
        ->toContain('b5aef749-7fa6-43da-a0b8-2d5ba017eb72.png')
        ->toContain('background-image: none !important')
        ->toContain('border-top: 4px solid var(--t4-accent)')
        ->toContain('@media (max-width: 991px)')
        ->toContain('@media (max-width: 767px)')
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->and($this->homeStyles)
        ->toContain('font-size: clamp(2.85rem, 4.15vw, 4.65rem)')
        ->not->toContain('body.theme-theme-03');
});
