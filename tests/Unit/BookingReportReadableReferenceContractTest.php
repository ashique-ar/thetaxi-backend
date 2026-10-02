<?php

it('keeps booking report preview references readable when business numbers are absent', function () {
    $source = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/booking-reports/booking-reports.component.ts'));

    expect($source)->toContain("return ['Booking reference', 'Customer'")
        ->toContain("booking.reference_number || 'Unreferenced booking'")
        ->not->toContain('booking.booking_number || booking.id');
});
