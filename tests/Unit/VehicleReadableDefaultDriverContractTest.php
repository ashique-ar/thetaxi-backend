<?php

it('projects a readable default driver on vehicle detail', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Vehicle/VehicleController.php'));
    $resource = file_get_contents(app_path('Http/Resources/Vehicle/VehicleResource.php'));

    expect($controller)->toContain("'defaultDriver.user'")
        ->and($resource)->toContain("'default_driver' => $this->whenLoaded('defaultDriver'")
        ->toContain("['name' =>")
        ->toContain("'code' => $driver->code");
});

it('shows slab service names instead of service type IDs', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/vehicle/components/vehicle-pricing/components/slab-definitions/slab-definition-management.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/vehicle/components/vehicle-pricing/components/slab-definitions/slab-definition-management.component.html'));

    expect($component)
        ->toContain('serviceDisplayName(service: PricingSlabServiceHealth)')
        ->toContain("this.serviceTypes().find((item) => item.id === service.service_type_id)?.name")
        ->toContain("'Service type unavailable'")
        ->and($template)
        ->toContain('serviceDisplayName(service)')
        ->not->toContain('service.service_name || service.service_type_id');
});
