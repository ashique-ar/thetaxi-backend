<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Http\Controllers\BookingController;
use App\Models\Service\ServiceType;

class MissingRouteDistancePricingSafetyTest extends TestCase
{
    public function test_route_pricing_never_substitutes_minimum_km_when_distance_is_missing(): void
    {
        $service = file_get_contents(app_path('Services/BookingFlowService.php'));

        $this->assertStringContainsString('$distanceCalculationFailed = $hasUsablePickup', $service);
        $this->assertStringContainsString('$this->isLocationPayloadUsable($params[\'dropoff_location\'])', $service);
        $this->assertStringContainsString('$availability = [];', $service);
        $this->assertStringContainsString("'distance_calculation_failed' => \$distanceCalculationFailed", $service);
        $this->assertStringContainsString('Verified journey distance is required before route pricing', $service);
        $this->assertStringContainsString('$hasRequestedRoute = !empty($params[\'pickup_location\'])', $service);
        $this->assertStringContainsString('if (!$hasRequestedRoute && !isset($inputs[\'total_distance\'])', $service);
    }

    public function test_public_results_explain_why_prices_are_withheld(): void
    {
        $view = file_get_contents(resource_path('views/search.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/BookingController.php'));

        $this->assertStringContainsString('$distanceCalculationFailed', $controller);
        $this->assertStringContainsString('We could not calculate a verified journey distance.', $view);
        $this->assertStringContainsString('Fares are temporarily unavailable.', $view);
    }

    public function test_day_rental_search_does_not_promote_pickup_to_a_missing_dropoff(): void
    {
        $controller = new class extends BookingController {
            public function __construct()
            {
            }

            public function transform(array $data, ServiceType $serviceType): array
            {
                return $this->transformSearchParams($data, $serviceType, 'day_rental');
            }

            protected function resolveServiceFormConfiguration(ServiceType $serviceType): array
            {
                return [
                    'fields' => [],
                    'field_mappings' => ['locations' => ['pickup_location' => 'pickup']],
                    'uses_dropoff_time' => false,
                    'allow_return_trip' => false,
                ];
            }
        };

        $params = $controller->transform([
            'date' => '2026-10-14',
            'pickup' => 'Colombo, Sri Lanka',
            'pickup_lat' => '6.9271',
            'pickup_lng' => '79.8612',
        ], new ServiceType(['id' => 'test-day-rental', 'code' => 'day_rental']));

        $this->assertSame('Colombo, Sri Lanka', $params['pickup_location']['address']);
        $this->assertEmpty($params['dropoff_location']['address']);
        $this->assertNull($params['dropoff_location']['latitude']);
        $this->assertNull($params['dropoff_location']['longitude']);
    }
}
