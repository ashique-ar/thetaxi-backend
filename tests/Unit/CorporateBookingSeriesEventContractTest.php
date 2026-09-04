<?php

it('applies corporate approval status to recurring-series siblings through model saves, not a mass query update', function () {
    $service = file_get_contents(app_path('Services/CorporateBookingService.php'));

    expect($service)
        ->not->toContain('$seriesQuery?->update($attributes)')
        ->toContain('$siblings->each(fn (Booking $sibling) => $sibling->update($attributes))');
});
