<?php

it('shows authorized Driver and Booking labels in log-sheet review', function () {
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/logsheet/components/logsheet-form/logsheet-form.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/logsheet/components/logsheet-form/logsheet-form.component.ts'));

    expect($template)->toContain('lookupType="drivers"', 'apiEndpoint="bookings:/bookings"', 'driverReviewLabel()', 'bookingReviewLabel()')
        ->not->toContain("get('driver_id')?.value", "get('booking_id')?.value")
        ->and($component)->toContain('driverReviewLabel()', 'bookingReviewLabel()', 'getSelectedOption()', 'getDisplayValue(option)');
});
