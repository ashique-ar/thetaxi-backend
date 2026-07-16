<?php

namespace Tests\Unit;

use App\Services\Pricing\PricingDefinitionOrchestrator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PricingDefinitionOrchestratorTest extends TestCase
{
    public function test_it_continues_after_missing_inputs_and_selects_the_next_valid_definition(): void
    {
        $first = $this->candidate('first', [
            'calculation_success' => false,
            'conditions_met' => false,
            'failure_reason' => 'missing_required_variables',
            'missing_variables' => ['waiting_minutes'],
        ]);
        $second = $this->candidate('second', [
            'calculation_success' => true,
            'conditions_met' => true,
            'total_amount' => 1250.0,
        ]);

        $resolved = (new PricingDefinitionOrchestrator())->resolve([$first, $second], []);

        self::assertTrue($resolved['matched']);
        self::assertSame($second, $resolved['definition']);
        self::assertSame(1250.0, $resolved['result']['total_amount']);
        self::assertSame('missing_required_variables', $resolved['candidate_failures'][0]['reason']);
        self::assertSame(['waiting_minutes'], $resolved['candidate_failures'][0]['missing_variables']);
    }

    public function test_it_records_an_exception_and_continues_to_the_next_candidate(): void
    {
        $broken = new class {
            public string $id = 'broken';
            public function calculatePrice(): array
            {
                throw new RuntimeException('Invalid formula');
            }
        };
        $valid = $this->candidate('valid', [
            'calculation_success' => true,
            'conditions_met' => true,
            'total_amount' => 500.0,
        ]);

        $resolved = (new PricingDefinitionOrchestrator())->resolve([$broken, $valid], []);

        self::assertTrue($resolved['matched']);
        self::assertSame('calculation_exception', $resolved['candidate_failures'][0]['reason']);
        self::assertSame('Invalid formula', $resolved['candidate_failures'][0]['message']);
    }

    public function test_it_returns_auditable_failures_when_no_definition_matches(): void
    {
        $resolved = (new PricingDefinitionOrchestrator())->resolve([
            $this->candidate('conditional', [
                'calculation_success' => false,
                'conditions_met' => false,
                'failure_reason' => 'conditions_not_met',
            ]),
        ], []);

        self::assertFalse($resolved['matched']);
        self::assertNull($resolved['definition']);
        self::assertNull($resolved['result']);
        self::assertSame('conditional', $resolved['candidate_failures'][0]['definition_id']);
    }

    public function test_it_rejects_an_invalid_total_and_uses_the_next_safe_candidate(): void
    {
        $negative = $this->candidate('negative', [
            'calculation_success' => true,
            'conditions_met' => true,
            'total_amount' => -50,
        ]);
        $valid = $this->candidate('valid', [
            'calculation_success' => true,
            'conditions_met' => true,
            'total_amount' => 725.0,
        ]);

        $resolved = (new PricingDefinitionOrchestrator())->resolve([$negative, $valid], []);

        self::assertTrue($resolved['matched']);
        self::assertSame($valid, $resolved['definition']);
        self::assertSame('invalid_total', $resolved['candidate_failures'][0]['reason']);
    }

    private function candidate(string $id, array $result): object
    {
        return new class($id, $result) {
            public function __construct(public string $id, private array $result) {}
            public function calculatePrice(): array
            {
                return $this->result;
            }
        };
    }
}
