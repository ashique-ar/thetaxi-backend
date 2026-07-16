<?php

use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('keeps booking field labels outside bordered controls in both public themes', function () {
    $fieldTemplate = file_get_contents(resource_path('views/components/dynamic-form-field.blade.php'));
    $sharedStyles = file_get_contents(public_path('assets/css/booking-form.css'));

    expect($fieldTemplate)
        ->toContain('<div class="booking-field">')
        ->toContain('<label class="input-label">{{ $label }}</label>')
        ->toContain('<label class="input-label" for="{{ $elementId }}">{{ $label }}</label>')
        ->toContain('<div class="single-search-box checkbox-field">')
        ->not->toContain('<div class="single-search-box checkbox-field">' . PHP_EOL . '            <label');

    expect(Str::squish($sharedStyles))
        ->toContain('.filter-wrapper .booking-field > .input-label, .t2-filter-wrapper .booking-field > .input-label')
        ->toContain('.filter-wrapper .booking-field > .single-search-box, .t2-filter-wrapper .booking-field > .single-search-box');
});
