<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->partners = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/partner-register.blade.php');
    $this->featured = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/featured-vehicles.blade.php');
    $this->vehicleCard = file_get_contents($projectRoot . '/resources/views/components/vehicle-card.blade.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
});

it('selects isolated Theme 03 homepage sections without removing released-theme markup', function () {
    expect($this->home)
        ->toContain("@include('partials.themes.theme-03.partner-register')")
        ->toContain("@include('partials.themes.theme-03.featured-vehicles')")
        ->toContain('class="partner-section mb-100"')
        ->toContain('class="featured-vehicles-section home4-offer-slider-section mb-100"')
        ->toContain("isset(\$partners) && \$partners->count() > 0")
        ->toContain("isset(\$featuredVehicles) && count(\$featuredVehicles['data']) > 0");
});

it('keeps the partner register on the existing collection and settings owners', function () {
    expect($this->partners)
        ->toContain("\$settings['partner_section_title']")
        ->toContain('@foreach ($partners as $partner)')
        ->toContain("\$partner->link ?? '#'")
        ->toContain('$partner->image ? s3_asset($partner->image)')
        ->toContain("s3_asset(\$settings['partner_logo_1'])")
        ->toContain('alt="{{ $partner->title }}"')
        ->toContain('class="t3-partner-register__rail marquee"')
        ->toContain('class="t3-partner-register__items marquee__group"')
        ->not->toContain('<script')
        ->not->toContain('<form');
});

it('preserves the featured fleet data, route and shared vehicle-card inputs', function () {
    expect($this->featured)
        ->toContain("array_chunk(\$featuredVehicles['data'], 4)")
        ->toContain("\$vehicle['pricing_info']")
        ->toContain("\$vehicle['enhanced_pricing']")
        ->toContain("\$vehicle['service_features']")
        ->toContain("\$vehicle['available_count']")
        ->toContain("\$vehicle['total_count']")
        ->toContain("\$vehicle['recommended']")
        ->toContain('<x-vehicle-card')
        ->toContain(':pricing="$pricing"')
        ->toContain(':enhancedPricing="$enhancedPricing"')
        ->toContain(':serviceFeatures="$serviceFeatures"')
        ->toContain(':availability="$availability"')
        ->toContain(':searchId="$featuredVehicleSearch[\'id\']"')
        ->toContain(':isRecommended="$isRecommended"')
        ->toContain(':showBookNow="true"')
        ->toContain(':showViewDetails="false"')
        ->toContain("route('cms.index', ['contentType' => 'ride_now'])");
});

it('retains the existing carousel and cart integration hooks', function () {
    expect($this->featured)
        ->toContain('featured-vehicles-slider')
        ->toContain('featured-vehicles-prev')
        ->toContain('featured-vehicles-next')
        ->toContain('featured-vehicles-pagination')
        ->and($this->home)
        ->toContain('new Swiper(".featured-vehicles-slider"')
        ->toContain('nextEl: ".featured-vehicles-next"')
        ->toContain('prevEl: ".featured-vehicles-prev"')
        ->toContain("$('.featured-vehicles-slider .add-to-cart-btn')")
        ->toContain("$('.featured-vehicles-slider .book-now-btn')");
});

it('continues to delegate every booking eligibility state to the shared card', function () {
    expect($this->vehicleCard)
        ->toContain("\$allowBooking = \$vehicle['allow_booking'] ?? true")
        ->toContain('$hasPricing =')
        ->toContain('$isGroupActive =')
        ->toContain('$hasAvailableVehicles =')
        ->toContain('$showQuotationButton =')
        ->toContain('$canAddToCart =')
        ->toContain('class="btn btn-primary w-100 mb-2 book-now-btn"')
        ->toContain('class="btn btn-outline-primary w-100 add-to-cart-btn"')
        ->toContain('request-quotation-btn')
        ->toContain('Not Available');
});

it('uses a scoped trust register and catalogue treatment for Theme 03', function () {
    expect($this->styles)
        ->toContain('body.theme-theme-03 .t3-partner-register')
        ->toContain('body.theme-theme-03 .t3-partner-register__items')
        ->toContain('animation: none !important')
        ->toContain('filter: grayscale(1)')
        ->toContain('body.theme-theme-03 .t3-featured-fleet')
        ->toContain('body.theme-theme-03 .t3-featured-fleet__heading')
        ->toContain('body.theme-theme-03 .t3-featured-fleet .vehicle-card.vehicle-card--theme-03')
        ->toContain('body.theme-theme-03 .t3-featured-fleet .featured-vehicles-pagination')
        ->toContain('body.theme-theme-03 .t3-featured-fleet .vehicle-card--theme-03 .vehicle-actions .btn');
});

