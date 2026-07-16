<?php

use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('keeps booking field labels outside bordered controls in both public themes', function () {
    $fieldTemplate = file_get_contents(resource_path('views/components/dynamic-form-field.blade.php'));
    $formTemplate = file_get_contents(resource_path('views/components/dynamic-booking-form.blade.php'));
    $predefinedLocationTemplate = file_get_contents(resource_path('views/components/predefined-location-selector.blade.php'));
    $sharedStyles = file_get_contents(public_path('assets/css/booking-form.css'));
    $themeTwoStyles = file_get_contents(public_path('assets/css/theme-02-tw.css'));
    $vehicleDetailsTemplate = file_get_contents(resource_path('views/vehicle-details.blade.php'));

    expect($fieldTemplate)
        ->toContain('<div class="booking-field">')
        ->toContain('<label class="input-label">{{ $label }}</label>')
        ->toContain('<label class="input-label" for="{{ $elementId }}">{{ $label }}</label>')
        ->toContain('<div class="single-search-box checkbox-field">')
        ->not->toContain('<div class="single-search-box checkbox-field">' . PHP_EOL . '            <label');

    foreach ([$fieldTemplate, $formTemplate, $predefinedLocationTemplate] as $activeTemplate) {
        expect($activeTemplate)
            ->not->toMatch('/class="single-search-box[^\"]*">\s*<(?:label|span)\b[^>]*class="[^"]*input-label/i');
    }

    expect(Str::squish($formTemplate))
        ->toContain('<div class="booking-field"> <label class="input-label">')
        ->toContain('<div class="booking-field custom-location-box');

    expect(Str::squish($predefinedLocationTemplate))
        ->toContain('<div class="booking-field"> <label class="input-label">')
        ->toContain('<div class="booking-field custom-location-box"');

    expect(Str::squish($sharedStyles))
        ->toContain('.filter-wrapper .booking-field > .input-label, .t2-filter-wrapper .booking-field > .input-label')
        ->toContain('.filter-wrapper .booking-field > .single-search-box, .t2-filter-wrapper .booking-field > .single-search-box')
        ->toContain('.filter-wrapper .filter-input-wrap .filter-input.show, .t2-filter-wrapper .filter-input.show { grid-template-columns: minmax(0, 1fr) !important; }');

    expect(Str::squish($themeTwoStyles))
        ->toContain('.t2-filter-wrapper .filter-input.show { display: grid !important; }');

    expect(Str::squish($vehicleDetailsTemplate))
        ->toContain('.booking-form-card .filter-wrapper .filter-input-wrap .filter-input.show { display: grid !important; grid-template-columns: minmax(0, 1fr) !important; text-align: left !important; }')
        ->not->toContain('.return-trip-details { display: block !important; }');
});
