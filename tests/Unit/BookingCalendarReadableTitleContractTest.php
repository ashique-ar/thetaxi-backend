<?php

it('uses a readable booking calendar title when no business reference exists', function () {
    $source = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/booking-calendar/booking-calendar.component.ts'));

    expect($source)->toContain("booking.reference_number || 'Unreferenced booking'")
        ->not->toContain('booking.reference_number || booking.id');
});
