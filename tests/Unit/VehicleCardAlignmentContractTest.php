<?php

uses(Tests\TestCase::class);

it('keeps shared vehicle card content in aligned slots for every theme', function (): void {
    $card = file_get_contents(resource_path('views/components/vehicle-card.blade.php'));

    expect($card)
        ->toContain('$showPublicPrice = $hasPricing && !$showQuotationButton;')
        ->toContain('class="vehicle-card-price-slot"')
        ->toContain('class="vehicle-card-amenities-slot"')
        ->toContain('class="vehicle-card-details-slot"')
        ->toContain('class="vehicle-card-features-slot"')
        ->toContain('display: grid !important;')
        ->toContain('grid-template-rows: 38px 26px 84px 30px 34px 40px minmax(86px, auto);');
});
