<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->presenter = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/booking-form.blade.php');
    $this->booking = file_get_contents($projectRoot . '/resources/views/components/booking-form.blade.php');
    $this->dynamicForm = file_get_contents($projectRoot . '/resources/views/components/dynamic-booking-form.blade.php');
    $this->dynamicField = file_get_contents($projectRoot . '/resources/views/components/dynamic-form-field.blade.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
    $this->script = file_get_contents($projectRoot . '/public/assets/js/themes/theme-03/shell.js');
});

it('uses a Theme 03 presenter while leaving released-theme homepage markup available', function () {
    expect($this->home)
        ->toContain("@if (is_theme('theme-03'))")
        ->toContain("@include('partials.themes.theme-03.booking-form')")
        ->toContain("@include('components.booking-form')")
        ->and($this->presenter)
        ->toContain('data-t3-journey-desk')
        ->toContain("@include('components.booking-form')")
        ->not->toContain('<form')
        ->not->toContain("route('booking.search')")
        ->not->toContain('<script');
});

it('preserves every database-owned service tab and the dynamic-form owner', function () {
    expect($this->booking)
        ->toContain("Cache::remember('booking_form_tabs'")
        ->toContain('BookingFormTab::getOrderedTabs()')
        ->toContain("'airport_transfers'")
        ->toContain("'ride_now'")
        ->toContain("'day_rental'")
        ->toContain("'corporate'")
        ->toContain("'wedding_hire'")
        ->toContain("'self_drive'")
        ->toContain("'with_driver'")
        ->toContain('data-service="{{ $tab->code }}"')
        ->toContain('data-form-service="{{ $getFormServiceCodeForTab($tab) }}"')
        ->toContain("@include('components.dynamic-booking-form'");
});

it('preserves search and inquiry routes plus submitted service identity', function () {
    expect($this->dynamicForm)
        ->toContain("route('booking.enquiry')")
        ->toContain("route('booking.search')")
        ->toContain("method=\"{{ \$isInquiry ? 'POST' : 'GET' }}\"")
        ->toContain('name="service_type"')
        ->toContain('value="{{ $serviceCode }}"')
        ->toContain('button type="submit"')
        ->toContain('$settings[\'booking_submit_inquiry_label\']')
        ->toContain('$settings[\'booking_search_submit_label\']');
});

it('keeps all supported field and location variants in the shared renderer', function () {
    expect($this->dynamicField)
        ->toContain("@case('location')")
        ->toContain("@case('predefined_or_custom')")
        ->toContain("@case('airport')")
        ->toContain("@case('conditional')")
        ->toContain("@case('date')")
        ->toContain("@case('time')")
        ->toContain("@case('select')")
        ->toContain("@case('radio')")
        ->toContain("@case('checkbox')")
        ->toContain("@case('textarea')")
        ->toContain("@case('number')")
        ->toContain("@case('hidden')")
        ->toContain("@case('package_select')")
        ->toContain('components.predefined-location-selector')
        ->toContain('components.airport-select')
        ->and($this->dynamicForm)
        ->toContain('return-trip-section')
        ->toContain('name="is_return_trip"')
        ->toContain('name="return_date"')
        ->toContain('name="return_time"');
});

it('styles the service rail, controls, packages, selectors and responsive states only inside Theme 03', function () {
    expect($this->styles)
        ->toContain('body.theme-theme-03 .t3-journey-desk .filter-wrapper')
        ->toContain('body.theme-theme-03 .t3-journey-desk .filter-item-list')
        ->toContain('body.theme-theme-03 .t3-journey-desk .filter-input.show')
        ->toContain('body.theme-theme-03 .t3-journey-desk .single-search-box')
        ->toContain('body.theme-theme-03 .t3-journey-desk .transfer-type-toggle')
        ->toContain('body.theme-theme-03 .t3-journey-desk .pls-dropdown-list')
        ->toContain('body.theme-theme-03 .t3-journey-desk .package-buttons-wrapper')
        ->toContain('body.theme-theme-03 .t3-journey-desk :where(.return-trip-details, #return-transfer-fields)')
        ->toContain('body.theme-theme-03 .t3-journey-desk .booking-advance-note');
});

it('adds keyboard tab semantics without replacing the existing click behavior', function () {
    expect($this->script)
        ->toContain("document.querySelector('[data-t3-journey-desk]')")
        ->toContain("tabList.setAttribute('role', 'tablist')")
        ->toContain("tab.setAttribute('role', 'tab')")
        ->toContain("controlledForm.setAttribute('role', 'tabpanel')")
        ->toContain("const keys = ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End']")
        ->toContain('tabs[targetIndex].click()')
        ->not->toContain('.submit()');
});
