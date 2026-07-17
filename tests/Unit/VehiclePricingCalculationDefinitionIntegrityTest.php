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

    public function test_missing_required_variable_is_not_reported_as_a_matched_zero_price(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'required_charge';
        $definition->variables = [[
            'name' => 'required_charge',
            'type' => 'number',
            'is_required' => true,
        ]];

        $result = $definition->calculatePrice([]);

        $this->assertFalse($result['conditions_met']);
        $this->assertFalse($result['calculation_success']);
        $this->assertSame('missing_required_variables', $result['failure_reason']);
        $this->assertContains('required_charge', $result['missing_variables']);
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

    public function test_missing_required_slab_and_common_rates_default_to_zero(): void
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
