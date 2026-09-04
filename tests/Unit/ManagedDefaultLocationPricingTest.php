<?php

namespace Tests\Unit;

use App\Services\BookingFlowService;
use App\Services\GoogleMapsService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ManagedDefaultLocationPricingTest extends TestCase
{
    public function test_managed_address_defaults_are_distance_priced_when_legacy_coordinates_are_missing(): void
    {
        $from = ['address' => 'Bandaranaike International Airport'];
        $to = ['address' => 'Galle Face Hotel, Colombo'];

        $maps = Mockery::mock(GoogleMapsService::class);
        $maps->shouldReceive('distanceAndDuration')
            ->once()
            ->with($from, $to)
            ->andReturn(['distance_km' => 35.4, 'duration_seconds' => 2700]);
        $this->app->instance(GoogleMapsService::class, $maps);

        $method = new ReflectionMethod(BookingFlowService::class, 'calculateDistance');
        $result = $method->invoke(app(BookingFlowService::class), $from, $to);

        $this->assertSame(35.4, $result['distance_km']);
        $this->assertSame(2700, $result['duration_seconds']);
    }

    public function test_empty_managed_location_cannot_be_priced_as_a_route(): void
    {
        $maps = Mockery::mock(GoogleMapsService::class);
        $maps->shouldNotReceive('distanceAndDuration');
        $this->app->instance(GoogleMapsService::class, $maps);

        $method = new ReflectionMethod(BookingFlowService::class, 'calculateDistance');
        $result = $method->invoke(
            app(BookingFlowService::class),
            ['address' => ''],
            ['address' => 'Galle Face Hotel, Colombo']
        );

        $this->assertNull($result);
    }

    public function test_portal_requires_managed_default_address_and_coordinates_as_one_value(): void
    {
        $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/services-management/service-types/form-config/service-type-form-config.component.ts'));
        $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/services-management/service-types/form-config/service-type-form-config.component.html'));

        $this->assertStringContainsString('onDefaultLocationSelected', $component);
        $this->assertStringContainsString('incompleteDefaultLocation', $component);
        $this->assertStringContainsString('<app-location-autocomplete', $template);
        $this->assertStringContainsString('Filled from the selected default location', $template);
    }
}
