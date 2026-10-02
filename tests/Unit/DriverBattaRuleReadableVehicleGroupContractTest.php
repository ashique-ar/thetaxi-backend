<?php

it('shows a readable vehicle group label on Driver Batta Rules', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Driver/DriverBattaRuleController.php'));
    $resource = file_get_contents(app_path('Http/Resources/Driver/DriverBattaRuleResource.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/logsheet/components/batta-rules/batta-rules.component.html'));

    expect($controller)
        ->toContain("permission:driver-batta-rules.view", "DriverBattaRule::with('vehicleGroup')")
        ->and($resource)->toContain("'vehicle_group' => new VehicleGroupResource(\$this->whenLoaded('vehicleGroup'))")
        ->and($template)->toContain("row.vehicle_group?.name || 'Vehicle group unavailable'")
        ->not->toContain('row.vehicle_group_id');
});
