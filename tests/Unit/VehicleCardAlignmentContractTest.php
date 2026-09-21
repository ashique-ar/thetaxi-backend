<?php

uses(Tests\TestCase::class);

it('keeps shared vehicle card content in aligned slots for every theme', function (): void {
    $card = file_get_contents(resource_path('views/components/vehicle-card.blade.php'));

    expect($card)
        ->toContain('class="vehicle-card-price-slot"')
        ->toContain('class="vehicle-card-amenities-slot"')
        ->toContain('class="vehicle-card-details-slot"')
        ->toContain('class="vehicle-card-features-slot"')
        ->toContain('display: grid !important;')
        ->toContain('grid-template-rows: 48px 32px 140px 42px 60px 54px minmax(92px, auto);');
});
