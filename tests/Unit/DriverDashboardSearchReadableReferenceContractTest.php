<?php

it('searches drivers by readable fields instead of internal identifiers', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Driver/DriverController.php'));
    $dashboard = file_get_contents(base_path('../portal-thetaxi/src/app/modules/driver/components/driver-assignment-dashboard/driver-assignment-dashboard.component.ts'));

    expect($controller)->toContain("whereLikeInsensitive('code', \$search)")
        ->not->toContain("whereLikeInsensitive('id', \$search)", "orWhereLikeInsensitive('user_id', \$search)", "whereLikeInsensitive('device_uuid', \$search)", "whereLikeInsensitive('current_device_uuid', \$search)")
        ->and($dashboard)->toContain('placeholder="Driver name or code..."')
        ->not->toContain('Driver name, ID');
});
