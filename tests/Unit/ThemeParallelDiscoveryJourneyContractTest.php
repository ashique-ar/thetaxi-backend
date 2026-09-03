<?php

beforeEach(function () {
    $root = dirname(__DIR__, 2);

    $this->search = file_get_contents($root . '/resources/views/search.blade.php');
    $this->details = file_get_contents($root . '/resources/views/vehicle-details.blade.php');
    $this->vehicleCard = file_get_contents($root . '/resources/views/components/vehicle-card.blade.php');
    $this->cartDock = file_get_contents($root . '/resources/views/components/cart-summary-float.blade.php');
    $this->distance = file_get_contents($root . '/resources/views/components/distance-details.blade.php');
    $this->dynamicField = file_get_contents($root . '/resources/views/components/dynamic-form-field.blade.php');
    $this->locations = file_get_contents($root . '/resources/views/components/predefined-location-selector.blade.php');
    $this->cmsCard = file_get_contents($root . '/resources/views/components/cms-card.blade.php');
    $this->theme03 = file_get_contents($root . '/public/assets/css/themes/theme-03/pages.css');
    $this->theme04 = file_get_contents($root . '/public/assets/css/themes/theme-04/pages.css');
});

it('adds presentation hooks without changing search, quotation, vehicle or cart contracts', function () {
    expect($this->search)
        ->toContain("theme_class('discovery-results')")
        ->toContain('id="vehicleResultsSection"')
        ->toContain('id="searchBookingSection"')
        ->toContain("route('quotation.request')")
        ->toContain('name="vehicle_group_id"')
        ->toContain('name="search_id"')
        ->toContain('<x-vehicle-card')
        ->toContain('<x-cart-summary-float />');

    expect($this->details)
        ->toContain("theme_class('vehicle-details')")
        ->toContain('id="mainVehicleImage"')
        ->toContain('id="vehicleAddToCartBtn"')
        ->toContain('id="vehicleBookNowBtn"')
        ->toContain("@include('components.booking-form'");

    expect($this->vehicleCard)
        ->toContain("theme_class('vehicle-card')")
        ->toContain("route('vehicle.details'")
        ->toContain('$showQuotationButton')
        ->toContain('$canAddToCart')
        ->toContain('data-vehicle-group=')
        ->toContain('data-price=');

    expect($this->cartDock)
        ->toContain("theme_class('cart-summary-float')")
        ->toContain('id="cartSummaryFloat"')
        ->toContain('id="cartFloatItems"')
        ->toContain('id="closeCartFloat"');
});

it('preserves every dynamic field and location submission contract', function () {
    foreach (['location', 'date', 'time', 'datetime', 'select', 'radio', 'checkbox', 'textarea', 'number', 'hidden', 'package_select'] as $type) {
        expect($this->dynamicField)->toContain("@case('{$type}')");
    }

    expect($this->dynamicField)->toContain('type="datetime-local"');

    expect($this->dynamicField)
        ->toContain('name="{{ $submitAs }}"')
        ->toContain('name="{{ $submitAs }}_lat"')
        ->toContain('name="{{ $submitAs }}_lng"')
        ->toContain('package-radio-input')
        ->toContain('@error($submitAs)');

    expect($this->locations)
        ->toContain('name="{{ $name }}_type"')
        ->toContain('name="{{ $name }}"')
        ->toContain('name="{{ $name }}_lat"')
        ->toContain('name="{{ $name }}_lng"')
        ->toContain('name="{{ $name }}_predefined"')
        ->toContain('role="combobox"')
        ->toContain('role="listbox"');
});

it('retains distance, vehicle-state and CMS normalization owners', function () {
    expect($this->distance)
        ->toContain("theme_class('distance-details-section')")
        ->toContain("\$distanceDetails['allowed_total_km']")
        ->toContain("\$distanceDetails['extra_km_price']")
        ->toContain("\$distanceDetails['journey_distance']")
        ->toContain("\$distanceDetails['minimum_km_applied']");

    foreach (['quotation_only', 'allow_booking', 'is_group_active', 'is_inquiry_only', 'service_requires_inquiry'] as $state) {
        expect($this->vehicleCard)->toContain("['{$state}']");
    }

    expect($this->cmsCard)
        ->toContain("\$template === 'theme-03-editorial-service'")
        ->toContain("\$template === 'theme-04-media-card'")
        ->toContain("\$template === 'theme-03-destination-index'")
        ->toContain("\$template === 'theme-03-itinerary'");
});

it('gives both themes complete discovery, vehicle, field, dock and responsive ownership', function () {
    $contracts = [
        '.discovery-results--theme-',
        '.search-summary-card',
        '.vehicle-results-grid',
        '.vehicle-card--theme-',
        '.no-results-card',
        '#requestQuotationModal',
        '.vehicle-details--theme-',
        '.vehicle-image-gallery',
        '.main-vehicle-image',
        '.vehicle-info-card',
        '.booking-form-card',
        '.distance-details-section--theme-',
        '.pls-dropdown-trigger',
        '.dynamic-conditional-location input',
        '.cart-summary-float--theme-',
        'env(safe-area-inset-bottom)',
        'max-height: calc(100dvh',
    ];

    foreach (['03' => $this->theme03, '04' => $this->theme04] as $theme => $styles) {
        foreach ($contracts as $contract) {
            expect($styles)->toContain(str_replace('--theme-', '--theme-' . $theme, $contract));
        }

        expect($styles)
            ->toContain('@media (max-width: 991px)')
            ->toContain('.booking-form-card { position: static; }')
            ->toContain('@media (max-width: 767px)')
            ->toContain('.vehicle-card-wrapper { width: 100%; }');
    }
});

it('keeps both production gates closed', function () {
    $root = dirname(__DIR__, 2);
    $env = file_get_contents($root . '/.env.example');
    $phpunit = file_get_contents($root . '/phpunit.xml');

    foreach (['03', '04'] as $theme) {
        expect($env)->toContain("WEBSITE_THEME_{$theme}_ENABLED=false")
            ->and($phpunit)->toContain("<env name=\"WEBSITE_THEME_{$theme}_ENABLED\" value=\"false\"/>");
    }
});
