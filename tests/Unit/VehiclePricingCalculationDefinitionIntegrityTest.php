<?php

namespace Tests\Unit;

use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase as BaseTestCase;

class VehiclePricingCalculationDefinitionIntegrityTest extends BaseTestCase
{
    public function test_valid_formula_can_use_bare_and_braced_declared_variables(): void
    {
        $errors = VehiclePricingCalculationDefinition::validateFormulaConfiguration(
            'slab_rate + ({waiting_minutes} * waiting_charge_per_minute)',
            [
                ['name' => 'slab_rate', 'type' => 'slab_rate'],
                ['name' => 'waiting_minutes', 'type' => 'duration'],
                ['name' => 'waiting_charge_per_minute', 'type' => 'common_rate'],
            ]
        );

        $this->assertSame([], $errors);
    }

    public function test_formula_validation_rejects_unknown_variables_and_invalid_syntax(): void
    {
        $unknownVariableErrors = VehiclePricingCalculationDefinition::validateFormulaConfiguration(
            'slab_rate + undeclared_charge',
            [['name' => 'slab_rate', 'type' => 'slab_rate']]
        );
        $syntaxErrors = VehiclePricingCalculationDefinition::validateFormulaConfiguration(
            'slab_rate +',
            [['name' => 'slab_rate', 'type' => 'slab_rate']]
        );

        $this->assertStringContainsString('undeclared variables', implode(' ', $unknownVariableErrors));
        $this->assertStringContainsString('must end', implode(' ', $syntaxErrors));
    }

    public function test_runtime_formula_evaluation_does_not_replace_unknown_variables_with_zero(): void
    {
        $method = new ReflectionMethod(VehiclePricingCalculationDefinition::class, 'evaluateFormulaWithVariables');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('undeclared variables');

        $method->invoke(
            new VehiclePricingCalculationDefinition(),
            'slab_rate + missing_rate',
            ['slab_rate' => 100]
        );
    }

    public function test_max_function_floors_hourly_package_overages_at_zero(): void
    {
        $method = new ReflectionMethod(VehiclePricingCalculationDefinition::class, 'evaluateFormulaWithVariables');
        $definition = new VehiclePricingCalculationDefinition();
        $formula = 'Hourly_Package + (max(0, total_distance - Minimum_KM) * extra_km_rate)'
            . ' + (max(0, duration_hours - Minimum_Hours) * extra_hour_rate)';

        $baseOnly = $method->invoke($definition, $formula, [
            'Hourly_Package' => 6900,
            'total_distance' => 25,
            'Minimum_KM' => 150,
            'extra_km_rate' => 150,
            'duration_hours' => 0,
            'Minimum_Hours' => 3,
            'extra_hour_rate' => 150,
        ]);
        $withOverages = $method->invoke($definition, $formula, [
            'Hourly_Package' => 6900,
            'total_distance' => 160,
            'Minimum_KM' => 150,
            'extra_km_rate' => 150,
            'duration_hours' => 5,
            'Minimum_Hours' => 3,
            'extra_hour_rate' => 150,
        ]);

        $this->assertSame(6900.0, $baseOnly);
        $this->assertSame(8700.0, $withOverages);
        $this->assertSame([], VehiclePricingCalculationDefinition::validateFormulaConfiguration($formula, [
            ['name' => 'Hourly_Package'],
            ['name' => 'total_distance'],
            ['name' => 'Minimum_KM'],
            ['name' => 'extra_km_rate'],
            ['name' => 'duration_hours'],
            ['name' => 'Minimum_Hours'],
            ['name' => 'extra_hour_rate'],
        ]));
    }

    public function test_on_meter_waiting_applies_free_minutes_only_to_pickup_waiting(): void
    {
        $method = new ReflectionMethod(VehiclePricingCalculationDefinition::class, 'evaluateFormulaWithVariables');
        $formula = 'base_fare + (max(actual_distance - included_km, 0) * distance_rate_per_km)'
            . ' + (max(pickup_waiting_minutes - pickup_free_waiting_minutes, 0) * pickup_waiting_charge_per_minute)'
            . ' + (hire_waiting_minutes * hire_waiting_charge_per_minute)';

        $total = $method->invoke(new VehiclePricingCalculationDefinition(), $formula, [
            'base_fare' => 0,
            'actual_distance' => 8,
            'included_km' => 0,
            'distance_rate_per_km' => 147.75,
            'pickup_waiting_minutes' => 12,
            'pickup_free_waiting_minutes' => 10,
            'pickup_waiting_charge_per_minute' => 13,
            'hire_waiting_minutes' => 7,
            'hire_waiting_charge_per_minute' => 15,
        ]);

        $this->assertSame(1313.0, $total);
    }

    public function test_unused_required_declarations_do_not_block_the_active_formula(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'base_rate';
        $definition->variables = [
            ['name' => 'base_rate', 'type' => 'number', 'is_required' => true],
            ['name' => 'legacy_extra_hours', 'type' => 'duration', 'is_required' => true],
        ];
        $method = new ReflectionMethod($definition, 'resolveAllVariables');

        $resolved = $method->invoke($definition, ['base_rate' => 6900], null, [], [], null, null);

        $this->assertSame(['base_rate' => 6900.0], $resolved);
    }

    public function test_legacy_required_flag_is_ignored_and_missing_variable_defaults_to_zero(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'required_charge';
        $definition->variables = [[
            'name' => 'required_charge',
            'type' => 'number',
            'is_required' => true,
        ]];

        $resolved = $this->resolveVariables($definition);

        $this->assertSame(0.0, (float) $resolved['required_charge']);
    }

    public function test_missing_optional_rate_defaults_to_zero(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'missing_common_rate';
        $definition->variables = [[
            'name' => 'missing_common_rate',
            'type' => 'common_rate',
            'is_required' => false,
        ]];

        $resolved = $this->resolveVariables($definition);

        $this->assertSame(0.0, (float) $resolved['missing_common_rate']);
    }

    public function test_missing_slab_and_common_rates_default_to_zero(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'slab_rate + driver_allowance';
        $definition->variables = [
            ['name' => 'slab_rate', 'type' => 'slab_rate', 'is_required' => true],
            ['name' => 'driver_allowance', 'type' => 'common_rate', 'is_required' => true],
        ];

        $resolved = $this->resolveVariables($definition);

        $this->assertSame(0.0, (float) $resolved['slab_rate']);
        $this->assertSame(0.0, (float) $resolved['driver_allowance']);
    }

    public function test_missing_distance_rate_defaults_to_zero(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'total_distance * service_rate_per_km + duration_minutes';
        $definition->variables = [
            ['name' => 'total_distance', 'type' => 'distance', 'is_required' => true],
            ['name' => 'service_rate_per_km', 'type' => 'common_rate', 'is_required' => true],
            ['name' => 'duration_minutes', 'type' => 'duration', 'is_required' => true],
        ];

        $method = new ReflectionMethod($definition, 'resolveAllVariables');
        $resolved = $method->invoke(
            $definition,
            ['total_distance' => 5.95, 'duration_minutes' => 26],
            null,
            [],
            [],
            null,
            null
        );

        $this->assertSame(0.0, (float) $resolved['service_rate_per_km']);
    }

    public function test_semantic_condition_operators_match_the_configuration_contract(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->conditions = [
            ['field' => 'duration_minutes', 'operator' => 'greater_than_or_equal', 'value' => 60],
            ['field' => 'booking_type', 'operator' => 'not_equals', 'value' => 'self_drive'],
        ];
        $method = new ReflectionMethod(VehiclePricingCalculationDefinition::class, 'evaluateConditions');

        $this->assertTrue($method->invoke($definition, [
            'duration_minutes' => 90,
            'booking_type' => 'with_driver',
        ]));
        $this->assertFalse($method->invoke($definition, [
            'duration_minutes' => 30,
            'booking_type' => 'with_driver',
        ]));
    }

    private function resolveVariables(VehiclePricingCalculationDefinition $definition): array
    {
        $method = new ReflectionMethod($definition, 'resolveAllVariables');

        return $method->invoke($definition, [], null, [], [], null, null);
    }
}
