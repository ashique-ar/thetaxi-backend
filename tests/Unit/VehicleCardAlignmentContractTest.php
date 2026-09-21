<?php

uses(Tests\TestCase::class);

it('keeps shared vehicle card content in aligned slots for every theme', function (): void {
    $card = file_get_contents(resource_path('views/components/vehicle-card.blade.php'));
    $themeOne = file_get_contents(public_path('assets/css/theme-01.css'));

    expect($card)
        ->toContain('$showPublicPrice = $hasPricing && !$showQuotationButton;')
        ->toContain("\$showQuotationButton ? 'vehicle-card--quotation' : ''")
        ->toContain("@if (\$showPublicPrice && isset(\$vehicle['refundable_deposit']))")
        ->toContain('class="vehicle-card-price-slot"')
        ->toContain('class="vehicle-card-amenities-slot"')
        ->toContain('class="vehicle-card-details-slot"')
        ->toContain('class="vehicle-card-features-slot"')
        ->toContain('.vehicle-card .vehicle-image-container>.vehicle-specs')
        ->toContain('backdrop-filter: blur(10px);')
        ->toContain('display: grid !important;')
        ->toContain('grid-template-rows: 38px 92px 38px 34px 40px minmax(86px, auto);')
        ->and($themeOne)
        ->toContain('grid-template-rows: 34px 82px 32px 28px 34px minmax(82px, auto) !important;')
        ->toContain('.theme-default .vehicle-card--quotation .vehicle-card-content')
        ->toContain('.vehicle-card-price-slot,')
        ->toContain('.theme-default .vehicle-card .vehicle-image-container > .vehicle-specs');
});
