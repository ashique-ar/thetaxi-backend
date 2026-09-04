<?php

namespace Tests\Unit;

use Tests\TestCase;

class MissingRouteDistancePricingSafetyTest extends TestCase
{
    public function test_route_pricing_never_substitutes_minimum_km_when_distance_is_missing(): void
    {
        $service = file_get_contents(app_path('Services/BookingFlowService.php'));

        $this->assertStringContainsString('$distanceCalculationFailed = $pickupLocation', $service);
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
        $this->assertStringContainsString('We could not verify the journey distance.', $view);
        $this->assertStringContainsString('No price has been displayed', $view);
    }
}
