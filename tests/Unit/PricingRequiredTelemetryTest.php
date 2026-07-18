<?php

namespace Tests\Unit;

use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Services\BookingFlowService;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class PricingRequiredTelemetryTest extends TestCase
{
    public function test_missing_required_journey_distance_is_not_synthesized_as_zero(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'journey_distance';
        $definition->variables = [[
            'name' => 'journey_distance',
            'type' => 'distance',
            'is_required' => true,
        ]];
        $kmMethod = new ReflectionMethod($definition, 'calculateKmOverages');
        $method = new ReflectionMethod($definition, 'resolveAllVariables');
        $kmCalculations = $kmMethod->invoke($definition, [], null, null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('journey_distance');

        $method->invoke(
            $definition,
            [],
            null,
            $kmCalculations,
            [],
            null,
            null
        );
    }

    public function test_explicit_zero_journey_distance_remains_a_valid_measurement(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'journey_distance';
        $definition->variables = [[
            'name' => 'journey_distance',
            'type' => 'distance',
            'is_required' => true,
        ]];
        $method = new ReflectionMethod($definition, 'resolveAllVariables');

        $resolved = $method->invoke(
            $definition,
            ['journey_distance' => 0],
            null,
            [
                'journey_distance' => 0,
                'allowed_km' => 0,
                'extra_km' => 0,
            ],
            [],
            null,
            null
        );

        self::assertSame(0.0, (float) $resolved['journey_distance']);
    }

    public function test_missing_duration_is_not_synthesized_as_a_twenty_four_hour_booking(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'prepareCalculationInputs');

        $inputs = $method->invoke($service, ['vehicle_group_id' => 'group-1']);

        self::assertArrayNotHasKey('duration_minutes', $inputs);
        self::assertArrayNotHasKey('duration_hours', $inputs);
        self::assertArrayNotHasKey('duration_days', $inputs);
        self::assertArrayNotHasKey('number_of_days', $inputs);
    }

    public function test_explicit_zero_duration_is_preserved(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'prepareCalculationInputs');

        $inputs = $method->invoke($service, [
            'vehicle_group_id' => 'group-1',
            'duration_minutes' => 0,
        ]);

        self::assertSame(0.0, $inputs['duration_minutes']);
        self::assertSame(0.0, $inputs['duration_hours']);
        self::assertSame(0.0, $inputs['duration_days']);
        self::assertSame(1, $inputs['number_of_days']);
    }

    public function test_required_package_overage_is_unresolved_without_an_allowance_source(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'allowed_km + extra_km';
        $definition->variables = [
            ['name' => 'allowed_km', 'type' => 'distance', 'is_required' => true],
            ['name' => 'extra_km', 'type' => 'distance', 'is_required' => true],
        ];
        $kmMethod = new ReflectionMethod($definition, 'calculateKmOverages');
        $resolveMethod = new ReflectionMethod($definition, 'resolveAllVariables');
        $kmCalculations = $kmMethod->invoke(
            $definition,
            ['journey_distance' => 50],
            null,
            null
        );

        $this->expectException(InvalidArgumentException::class);

        $resolveMethod->invoke(
            $definition,
            ['journey_distance' => 50],
            null,
            $kmCalculations,
            [],
            null,
            null
        );
    }

    public function test_weekend_and_holiday_flags_are_numeric_formula_inputs(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'prepareCalculationInputs');

        $weekend = $method->invoke($service, [
            'vehicle_group_id' => 'group-1',
            'duration_minutes' => 60,
            'from_date' => '2026-07-18', // Saturday
        ]);
        $holiday = $method->invoke($service, [
            'vehicle_group_id' => 'group-1',
            'duration_minutes' => 60,
            'from_date' => '2026-12-25',
        ]);

        self::assertSame(1, $weekend['is_weekend']);
        self::assertSame(1, $holiday['is_holiday']);
        self::assertIsInt($weekend['is_weekend']);
        self::assertIsInt($holiday['is_holiday']);
    }

    public function test_daily_allowance_rejects_a_reversed_date_time_range(): void
    {
        $definition = (new ReflectionClass(VehiclePricingCalculationDefinition::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($definition, 'calculateCalendarDays');

        $this->expectException(InvalidArgumentException::class);

        $method->invoke($definition, [
            'from_date' => '2026-07-18',
            'from_time' => '12:00',
            'to_date' => '2026-07-17',
            'to_time' => '09:00',
        ]);
    }

    public function test_booking_mode_is_derived_for_self_drive_and_chauffeur_conditions(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'prepareCalculationInputs');

        $selfDrive = $method->invoke($service, [
            'vehicle_group_id' => 'group-1',
            'duration_minutes' => 60,
            'is_self_driven' => true,
        ]);
        $chauffeur = $method->invoke($service, [
            'vehicle_group_id' => 'group-1',
            'duration_minutes' => 60,
            'is_self_driven' => false,
        ]);

        self::assertTrue($selfDrive['is_self_driven']);
        self::assertSame('self_drive', $selfDrive['booking_type']);
        self::assertFalse($chauffeur['is_self_driven']);
        self::assertSame('with_driver', $chauffeur['booking_type']);
    }

    public function test_explicit_zero_slab_kilometre_allowances_are_not_treated_as_missing(): void
    {
        $definition = (new ReflectionClass(VehiclePricingCalculationDefinition::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($definition, 'calculateKmOverages');

        $package = $method->invoke($definition, [
            'journey_distance' => 10,
        ], [
            'type' => 'flat_rate',
            'max_km_per_package' => 0,
            'max_km_per_day' => null,
        ], null);
        $daily = $method->invoke($definition, [
            'journey_distance' => 10,
            'duration_days' => 2,
        ], [
            'type' => 'per_day',
            'max_km_per_package' => null,
            'max_km_per_day' => 0,
        ], null);

        self::assertTrue($package['allowance_supplied']);
        self::assertSame(0.0, (float) $package['allowed_km']);
        self::assertSame(10.0, (float) $package['extra_km']);
        self::assertTrue($daily['allowance_supplied']);
        self::assertSame(0.0, (float) $daily['allowed_km']);
        self::assertSame(10.0, (float) $daily['extra_km']);
    }

    public function test_unlimited_slab_resolves_extra_kilometres_to_zero_without_distance_telemetry(): void
    {
        $definition = (new ReflectionClass(VehiclePricingCalculationDefinition::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($definition, 'calculateKmOverages');

        $result = $method->invoke($definition, [
            'from_date' => '2026-07-17',
            'to_date' => '2026-07-17',
            'from_time' => '16:15:00',
            'to_time' => '20:00:00',
        ], [
            'type' => 'days',
            'max_km_per_package' => null,
            'max_km_per_day' => null,
        ], null);

        self::assertSame('unlimited', $result['calculation_type']);
        self::assertSame(0.0, $result['extra_km']);
    }

    public function test_pricing_window_accepts_database_dates_that_already_include_midnight(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $method = new ReflectionMethod($definition, 'assertValidDateWindow');

        $method->invoke($definition, [
            'from_date' => '2026-07-17 00:00:00',
            'to_date' => '2026-07-17 00:00:00',
            'from_time' => '16:15:00',
            'to_time' => '20:00:00',
        ]);

        self::assertTrue(true);
    }
}
