<?php

it('does not render assignment UUIDs as Driver journey labels', function () {
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/driver/components/driver-journey-map/driver-journey-map.component.html'));

    expect($template)->toContain('assignment.booking_number', 'Unreferenced assignment')
        ->not->toContain('assignment.assignment_id');
});
