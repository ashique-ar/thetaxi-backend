<?php

namespace Tests\Unit;

use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
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

    private function normalize(array $inputs): array
    {
        $method = new ReflectionMethod(VehiclePricingCalculationDefinition::class, 'normalizeDurationUnits');

        return $method->invoke(new VehiclePricingCalculationDefinition(), $inputs);
    }
}
