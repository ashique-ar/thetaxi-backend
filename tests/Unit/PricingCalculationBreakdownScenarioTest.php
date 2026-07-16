<?php

namespace Tests\Unit;

use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class PricingCalculationBreakdownScenarioTest extends TestCase
{
    public function test_overtime_rate_uses_overtime_minutes_not_waiting_minutes(): void
    {
        $definition = (new ReflectionClass(VehiclePricingCalculationDefinition::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($definition, 'buildCalculationBreakdown');

        $breakdown = $method->invoke(
            $definition,
            [
                'waiting_minutes' => 5,
                'overtime_minutes' => 30,
                'waiting_charge_per_minute' => 10,
                'overtime_rate_per_minute' => 20,
            ],
            650,
            650,
            null,
            ['extra_km' => 0],
            ['adjustments' => []]
        );

        $lines = collect($breakdown['breakdown'])->keyBy('component');

        self::assertSame(50.0, (float) $lines['waiting_charge_per_minute']['amount']);
        self::assertSame(600.0, (float) $lines['overtime_rate_per_minute']['amount']);
        self::assertStringContainsString('30 minutes', $lines['overtime_rate_per_minute']['calculation']);
    }
}
