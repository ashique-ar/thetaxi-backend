<?php

it('includes inactive vehicles in inventory and historical performance reports', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/ReportsController.php'));

    expect($controller)
        ->toContain("'total_vehicles'          => Vehicle::withInactive()->count()")
        ->toContain("\$vehicles = Vehicle::withInactive()->with('group')")
        ->toContain('Vehicle::withInactive()->when(!empty($filters[\'vehicle_id\'])');
});
