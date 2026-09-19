<?php

use App\Services\HomepageVehicleSections;
use App\Services\VehicleService;
use App\Services\WebsiteSettingsService;

it('does not query vehicles when no homepage vehicle section is enabled', function () {
    $settings = Mockery::mock(WebsiteSettingsService::class);
    $settings->shouldReceive('get')->once()->with('homepage_vehicle_sections', '[]')
        ->andReturn(json_encode([['enabled' => false, 'service_type' => 'day_rental']]));
    $vehicles = Mockery::mock(VehicleService::class);
    $vehicles->shouldNotReceive('getFeaturedVehicles');

    expect((new HomepageVehicleSections($settings, $vehicles))->visible())->toBe([]);
});
