<?php

namespace Tests\Unit;

use App\Services\Pricing\PricingDefinitionOrchestrator;
use PHPUnit\Framework\TestCase;

class PricingInvalidTotalTest extends TestCase
{
    public function test_negative_candidate_total_is_rejected_and_next_definition_runs(): void
    {
        $invalid = $this->candidate('negative', -1);
        $valid = $this->candidate('valid', 250);

        $result = (new PricingDefinitionOrchestrator())->resolve([$invalid, $valid], []);

        self::assertTrue($result['matched']);
        self::assertSame($valid, $result['definition']);
        self::assertSame('invalid_total', $result['candidate_failures'][0]['reason']);
    }

    public function test_non_finite_candidate_total_is_never_a_match(): void
    {
        $result = (new PricingDefinitionOrchestrator())->resolve([
            $this->candidate('infinite', INF),
        ], []);

        self::assertFalse($result['matched']);
        self::assertSame('invalid_total', $result['candidate_failures'][0]['reason']);
    }

    private function candidate(string $id, mixed $total): object
    {
        return new class($id, $total) {
            public function __construct(public string $id, private mixed $total)
            {
            }

            public function calculatePrice(): array
            {
                return [
                    'calculation_success' => true,
                    'conditions_met' => true,
                    'total_amount' => $this->total,
                ];
            }
        };
    }
}
