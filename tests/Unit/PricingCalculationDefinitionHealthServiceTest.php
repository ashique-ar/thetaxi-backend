<?php

namespace Tests\Unit;

use App\Services\Pricing\PricingCalculationDefinitionHealthService;
use App\Services\VehiclePricingSlabConfigurationService;
use PHPUnit\Framework\TestCase;

class PricingCalculationDefinitionHealthServiceTest extends TestCase
{
    public function test_slab_health_is_skipped_when_formula_does_not_reference_slab_rate(): void
    {
        $slabs = $this->createMock(VehiclePricingSlabConfigurationService::class);
        $slabs->expects($this->never())->method('currentHealth');
        $service = new PricingCalculationDefinitionHealthService($slabs);

        $health = $service->analyzeDefinition($this->definition(
            'fixed',
            'base_charge',
            [['name' => 'base_charge', 'type' => 'fixed_value', 'default_value' => 500]]
        ));

        $this->assertTrue($health['ready_for_activation']);
        $this->assertSame('skipped', $health['checklist']['slab_dependency']['status']);
        $this->assertFalse($health['dependencies']['slab_rate']['required']);
    }

    public function test_referenced_slab_and_common_rate_dependencies_block_activation_on_errors(): void
    {
        $service = new PricingCalculationDefinitionHealthService(
            $this->createMock(VehiclePricingSlabConfigurationService::class)
        );
        $slabIssue = $this->dependencyIssue('slab_gap', 'slab_dependency');
        $commonIssue = $this->dependencyIssue('missing_common_rate_values', 'common_rate_dependency');

        $health = $service->analyzeDefinition(
            $this->definition('rates', 'slab_rate + waiting_charge', [
                ['name' => 'slab_rate', 'type' => 'slab_rate'],
                ['name' => 'waiting_charge', 'type' => 'common_rate'],
            ]),
            [
                'slab_rate' => ['required' => true, 'status' => 'error', 'issues' => [$slabIssue], 'details' => []],
                'common_rates' => [
                    'waiting_charge' => ['required' => true, 'status' => 'error', 'issues' => [$commonIssue], 'details' => []],
                ],
            ]
        );

        $this->assertFalse($health['ready_for_activation']);
        $this->assertSame('error', $health['checklist']['slab_dependency']['status']);
        $this->assertSame('error', $health['checklist']['common_rate_dependencies']['status']);
    }

    public function test_slab_rate_name_cannot_be_downgraded_to_a_runtime_number(): void
    {
        $service = new PricingCalculationDefinitionHealthService(
            $this->createMock(VehiclePricingSlabConfigurationService::class)
        );

        $health = $service->analyzeDefinition(
            $this->definition('wrong-type', 'slab_rate', [[
                'name' => 'slab_rate',
                'type' => 'number',
                'is_required' => true,
            ]]),
            [
                'slab_rate' => ['required' => true, 'status' => 'pass', 'issues' => [], 'details' => []],
            ]
        );

        $this->assertFalse($health['ready_for_activation']);
        $this->assertContains('slab_rate_type_mismatch', array_column($health['issues'], 'code'));
    }

    public function test_invalid_and_contradictory_conditions_are_reported(): void
    {
        $service = new PricingCalculationDefinitionHealthService(
            $this->createMock(VehiclePricingSlabConfigurationService::class)
        );
        $definition = $this->definition(
            'conditions',
            'base_charge',
            [['name' => 'base_charge', 'type' => 'fixed_value', 'default_value' => 100]]
        );
        $definition['conditions'] = [
            ['field' => 'duration_minutes', 'operator' => 'greater_than', 'value' => 120],
            ['field' => 'duration_minutes', 'operator' => 'less_than_or_equal', 'value' => 60],
        ];

        $health = $service->analyzeDefinition($definition);

        $this->assertFalse($health['healthy']);
        $this->assertContains('unreachable_conditions', array_column($health['issues'], 'code'));
        $this->assertSame('error', $health['checklist']['conditions']['status']);
    }

    public function test_equal_priority_candidates_with_overlapping_conditions_are_ambiguous(): void
    {
        $service = new PricingCalculationDefinitionHealthService(
            $this->createMock(VehiclePricingSlabConfigurationService::class)
        );
        $first = $this->definition('first', 'base_charge', [
            ['name' => 'base_charge', 'type' => 'fixed_value', 'default_value' => 100],
        ]);
        $second = $this->definition('second', 'base_charge', [
            ['name' => 'base_charge', 'type' => 'fixed_value', 'default_value' => 200],
        ]);

        $health = $service->analyzeService('service-1', [$first, $second], ['second']);

        $this->assertFalse($health['ready_for_activation']);
        $this->assertContains('ambiguous_active_candidates', array_column($health['issues'], 'code'));
        $this->assertSame('error', $health['definitions']['second']['checklist']['candidate_selection']['status']);
    }

    public function test_mutually_exclusive_candidates_at_the_same_priority_are_valid(): void
    {
        $service = new PricingCalculationDefinitionHealthService(
            $this->createMock(VehiclePricingSlabConfigurationService::class)
        );
        $selfDrive = $this->definition('self-drive', 'base_charge', [
            ['name' => 'base_charge', 'type' => 'fixed_value', 'default_value' => 100],
        ]);
        $selfDrive['conditions'] = [['field' => 'booking_type', 'operator' => 'equals', 'value' => 'self_drive']];
        $withDriver = $this->definition('with-driver', 'base_charge', [
            ['name' => 'base_charge', 'type' => 'fixed_value', 'default_value' => 100],
        ]);
        $withDriver['conditions'] = [['field' => 'booking_type', 'operator' => 'equals', 'value' => 'with_driver']];

        $health = $service->analyzeService('service-1', [$selfDrive, $withDriver]);

        $this->assertTrue($health['healthy']);
        $this->assertNotContains('ambiguous_active_candidates', array_column($health['issues'], 'code'));
    }

    public function test_scenario_context_separates_condition_only_identifiers_from_formula_inputs(): void
    {
        $service = new PricingCalculationDefinitionHealthService(
            $this->createMock(VehiclePricingSlabConfigurationService::class)
        );
        $definition = $this->definition('scenario', 'base_charge', [
            ['name' => 'base_charge', 'type' => 'fixed_value', 'default_value' => 100],
        ]);
        $definition['conditions'] = [
            ['field' => 'from_date', 'operator' => 'not_empty', 'value' => true],
            ['field' => 'is_weekend', 'operator' => 'equals', 'value' => true],
            ['field' => 'customer_type', 'operator' => 'equals', 'value' => 'corporate'],
            ['field' => 'package_id', 'operator' => 'exists', 'value' => true],
        ];

        $health = $service->analyzeDefinition($definition);

        $this->assertContains('from_date', $health['scenario_context']['temporal_fields']);
        $this->assertContains('is_weekend', $health['scenario_context']['temporal_fields']);
        $this->assertContains('customer_type', $health['scenario_context']['customer_fields']);
        $this->assertContains('package_id', $health['scenario_context']['package_fields']);
        $this->assertTrue($health['scenario_context']['identifier_fields_are_condition_only']);
    }

    /** @return array<string, mixed> */
    private function definition(string $id, string $formula, array $variables): array
    {
        return [
            'id' => $id,
            'name' => ucfirst($id),
            'service_type_id' => 'service-1',
            'formula' => $formula,
            'variables' => $variables,
            'conditions' => [],
            'status' => 'active',
            'priority' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function dependencyIssue(string $code, string $category): array
    {
        return [
            'code' => $code,
            'severity' => 'error',
            'category' => $category,
            'service_type_id' => 'service-1',
            'definition_ids' => ['rates'],
            'message' => 'Dependency is invalid.',
            'recommendation' => 'Correct the dependency.',
        ];
    }
}
