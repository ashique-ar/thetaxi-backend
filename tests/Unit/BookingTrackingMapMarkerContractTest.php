<?php

test('booking tracking keeps planned markers and separates point dots from arrows', function () {
    $component = file_get_contents(
        dirname(__DIR__, 3) . '/portal-thetaxi/src/app/modules/booking/components/booking-management/booking-management.component.ts'
    );

    expect($component)
        ->toContain('planned_pickup: pickup')
        ->toContain('planned_dropoff: dropoff')
        ->toContain('const offset = Math.floor(step / 2)')
        ->toContain('index % step !== offset');
});
