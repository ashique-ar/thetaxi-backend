<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Vehicle\VehiclePricing\VehiclePricingCalculationDefinitionController;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Services\PricingVariableService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class VehiclePricingDurationMinutesTest extends TestCase
{
    public function test_hour_inputs_are_converted_to_minutes(): void
    {
        $inputs = $this->normalize([
            'duration_hours' => 1.5,
            'extra_hours' => 0.25,
            'waiting_hours' => 0.5,
            'recovery_hours' => 2,
            'overtime_hours' => 1.25,
        ]);

        $this->assertSame(90.0, $inputs['duration_minutes']);
        $this->assertSame(15.0, $inputs['extra_minutes']);
        $this->assertSame(30.0, $inputs['waiting_minutes']);
        $this->assertSame(120.0, $inputs['recovery_minutes']);
        $this->assertSame(75.0, $inputs['overtime_minutes']);
    }

    public function test_minute_inputs_are_converted_to_fractional_hours(): void
    {
        $inputs = $this->normalize([
            'duration_minutes' => 90,
            'extra_minutes' => 15,
            'waiting_minutes' => 30,
            'recovery_minutes' => 120,
            'overtime_minutes' => 75,
        ]);

        $this->assertSame(1.5, $inputs['duration_hours']);
        $this->assertSame(0.25, $inputs['extra_hours']);
        $this->assertSame(0.5, $inputs['waiting_hours']);
        $this->assertSame(2.0, $inputs['recovery_hours']);
        $this->assertSame(1.25, $inputs['overtime_hours']);
    }

    public function test_per_minute_common_rate_uses_minutes(): void
    {
        $rate = (new ReflectionClass(VehiclePricingCommonRateDefinition::class))
            ->newInstanceWithoutConstructor();
        $rate->setRawAttributes([
            'common_rate_type' => 'per_minute',
            'value' => 12.5,
        ]);

        $this->assertSame(187.5, $rate->calculateAmount(0, minutes: 15));
    }

    public function test_calculation_definition_always_builds_an_updated_example(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'duration_minutes * waiting_charge_per_minute';
        $definition->variables = [
            ['name' => 'duration_minutes', 'type' => 'duration', 'default_value' => 30],
            ['name' => 'waiting_charge_per_minute', 'type' => 'common_rate', 'default_value' => 5],
        ];

        $example = $definition->getCalculationExample();

        $this->assertSame('30 * 5', $example['substituted_formula']);
        $this->assertSame(150.0, $example['result']);
    }

    public function test_hour_and_minute_operational_inputs_are_system_managed(): void
    {
        $method = new ReflectionMethod(PricingVariableService::class, 'isVariableCustomizable');
        $service = new PricingVariableService();

        foreach (['waiting', 'recovery', 'overtime'] as $prefix) {
            $this->assertFalse($method->invoke($service, "{$prefix}_hours", 'duration'));
            $this->assertFalse($method->invoke($service, "{$prefix}_minutes", 'duration'));
        }
    }

    public function test_calculation_tester_normalizes_exact_minutes_without_forcing_a_day(): void
    {
        $controller = (new ReflectionClass(VehiclePricingCalculationDefinitionController::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($controller, 'normalizeDurationInputs');

        $shortTrip = $method->invoke($controller, ['duration_minutes' => 45]);
        $longTrip = $method->invoke($controller, ['duration_minutes' => 1500]);

        $this->assertSame(0.75, $shortTrip['duration_hours']);
        $this->assertSame(0, $shortTrip['duration_days']);
        $this->assertSame(25.0, $longTrip['duration_hours']);
        $this->assertSame(2, $longTrip['duration_days']);
    }

    public function test_minimum_charge_is_visible_in_the_calculation_breakdown(): void
    {
        $method = new ReflectionMethod(
            VehiclePricingCalculationDefinition::class,
            'buildCalculationBreakdown'
        );
        $result = $method->invoke(
            new VehiclePricingCalculationDefinition(),
            ['slab_rate' => 100.0],
            250.0,
            250.0,
            null,
            ['extra_km' => 0, 'journey_distance' => 0],
            ['adjustments' => [[
                'type' => 'minimum_charge',
                'name' => 'Minimum Charge',
                'amount' => 150.0,
                'calculation' => 'Minimum 250 applied to 100',
            ]]]
        );

        $minimumLine = collect($result['breakdown'])
            ->firstWhere('component', 'minimum_charge');

        $this->assertSame('Minimum Charge', $minimumLine['description']);
        $this->assertSame(150.0, $minimumLine['amount']);
    }

    public function test_minute_slab_breakdown_describes_the_exact_minutes(): void
    {
        $method = new ReflectionMethod(
            VehiclePricingCalculationDefinition::class,
            'buildCalculationBreakdown'
        );
        $result = $method->invoke(
            new VehiclePricingCalculationDefinition(),
            ['slab_rate' => 500.0],
            500.0,
            500.0,
            ['type' => 'minutes', 'duration_minutes' => 45, 'duration_hours' => 0.75, 'duration_days' => 0],
            ['extra_km' => 0, 'journey_distance' => 0],
            []
        );

        $this->assertSame(
            'Based on minutes slab for 45 minutes',
            $result['breakdown'][0]['calculation']
        );
    }

    private function normalize(array $inputs): array
    {
        $method = new ReflectionMethod(VehiclePricingCalculationDefinition::class, 'normalizeDurationUnits');

        return $method->invoke(new VehiclePricingCalculationDefinition(), $inputs);
    }
}
