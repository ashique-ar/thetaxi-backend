<?php

uses(Tests\TestCase::class);

it('never displays internal IDs in the driver settlement table', function () {
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/logsheet/components/settlement-dashboard/settlement-dashboard.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/logsheet/components/settlement-dashboard/settlement-dashboard.component.ts'));

    expect($template)
        ->toContain('row.booking?.booking_number', 'Booking reference unavailable', 'driverLabel(row)', 'Vehicle group unavailable')
        ->not->toContain('row.booking_id', 'row.driver_id', 'row.vehicle_group_id');
    expect($component)->toContain('Driver unavailable');
});
