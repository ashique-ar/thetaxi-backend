<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->projectRoot = $projectRoot;
    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->header = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-04/header.blade.php');
    $this->hero = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-04/hero.blade.php');
    $this->booking = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-04/booking-form.blade.php');
    $this->footer = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-04/footer.blade.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-04/theme-04.css');
    $this->pageStyles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-04/pages.css');
    $this->script = file_get_contents($projectRoot . '/public/assets/js/themes/theme-04/shell.js');
    $this->cmsCard = file_get_contents($projectRoot . '/resources/views/components/cms-card.blade.php');
});

it('pins the supplied Theme 04 reference as the canonical design source', function () {
    $reference = dirname($this->projectRoot) . '/b5aef749-7fa6-43da-a0b8-2d5ba017eb72.png';
    $size = getimagesize($reference);

    expect($reference)->toBeFile()
        ->and($size[0])->toBe(1086)
        ->and($size[1])->toBe(1448)
        ->and($this->styles)->toContain('b5aef749-7fa6-43da-a0b8-2d5ba017eb72.png');
});

it('keeps every completed homepage family on separate Theme 03 and Theme 04 presenters', function () {
    foreach (['booking-form', 'partner-register', 'featured-vehicles', 'services', 'destinations', 'packages', 'offer-slider', 'why-choose-us', 'testimonials', 'blog-editorial', 'faq'] as $partial) {
        expect($this->home)
            ->toContain("partials.themes.theme-03.{$partial}")
            ->toContain("partials.themes.theme-04.{$partial}");
    }
});

it('keeps the Theme 04 hero on existing managed banner owners', function () {
    expect($this->hero)
        ->toContain("\$settings['banner_heading']")
        ->toContain("\$settings['banner_subheading']")
        ->toContain('get_hero_slides($settings)')
        ->toContain("\$settings['site_tagline']")
        ->toContain('shared-hero-swiper')
        ->toContain("@include('partials.hero-media'")
        ->not->toContain('<form')
        ->not->toContain('<script');
});

it('wraps rather than duplicates the shared booking behavior', function () {
    expect($this->booking)
        ->toContain("@include('components.booking-form')")
        ->toContain('data-t4-journey-desk')
        ->not->toContain('<form')
        ->and($this->script)
        ->toContain('.filter-item-list > .single-item')
        ->toContain('.filter-input-wrap > form.filter-input')
        ->toContain('tab.setAttribute(\'role\', \'tab\')')
        ->toContain('tabs[target].click()');
});

it('preserves header routes, currency, contact and cart integration hooks', function () {
    expect($this->header)
        ->toContain("route('home')")
        ->toContain("route('corporate-transfers')")
        ->toContain("route('rate-chart')")
        ->toContain("route('about')")
        ->toContain("route('inquiry')")
        ->toContain("route('checkout')")
        ->toContain('class="currency-option')
        ->toContain('data-currency=')
        ->toContain('id="cartBadge"')
        ->toContain('id="mobileCartBadge"')
        ->toContain('data-t4-menu-open')
        ->toContain('data-t4-menu-close');
});

it('retains all configurable footer families', function () {
    expect($this->footer)
        ->toContain("\$settings['footer_newsletter_enabled']")
        ->toContain("['services' => 'theme04ServicesLinks', 'routes' => 'theme04RoutesLinks', 'support' => 'theme04SupportLinks']")
        ->toContain('footer_{$group}_link_{$index}_text')
        ->toContain('footer_{$group}_link_{$index}_url')
        ->toContain("\$settings['company_address']")
        ->toContain("\$settings['company_phone']")
        ->toContain("\$settings['company_whatsapp']")
        ->toContain("\$settings['company_email']")
        ->toContain("\$settings['footer_payment_methods_enabled']")
        ->toContain("\$settings['footer_copyright_text']");
});

it('uses one normalized Theme 04 CMS card contract across managed content compositions', function () {
    expect($this->cmsCard)
        ->toContain("\$template === 'theme-04-media-card'")
        ->toContain('{{ $imageUrl }}')
        ->toContain('{{ $detailLink }}')
        ->toContain('{{ $categoryLink }}')
        ->toContain('{{ $title }}')
        ->toContain('{{ $excerpt }}')
        ->toContain('$showPrice && $price')
        ->toContain('$showDuration && $duration')
        ->toContain('$showRating && $rating > 0')
        ->toContain('$isSpecialOffer && $discount > 0');
});

it('implements the reference visual vocabulary only inside Theme 04', function () {
    expect($this->styles)
        ->toContain('--t4-accent')
        ->toContain('body.theme-theme-04 .t4-header')
        ->toContain('body.theme-theme-04 .t4-hero')
        ->toContain('body.theme-theme-04 .t4-booking-panel')
        ->toContain('body.theme-theme-04 .t4-fleet')
        ->toContain('body.theme-theme-04 .t4-services')
        ->toContain('body.theme-theme-04 .t4-proof')
        ->toContain('body.theme-theme-04 .t4-testimonials')
        ->toContain('body.theme-theme-04 .t4-faq')
        ->toContain('body.theme-theme-04 .t4-footer')
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->not->toContain('body.theme-theme-03');

    expect($this->pageStyles)
        ->toContain('body.theme-theme-04 .t4-site-main')
        ->toContain('body.theme-theme-04:not(.theme-page-home) .breadcrumb-section')
        ->not->toContain('body.theme-theme-03');
});
