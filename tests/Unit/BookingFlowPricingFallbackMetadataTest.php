<?php

namespace Tests\Unit;

use App\Services\BookingFlowService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class BookingFlowPricingFallbackMetadataTest extends TestCase
{
    public function test_fallback_preserves_the_actual_candidate_failure(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'calculateFallbackPricing');

        $result = $method->invoke($service, [], 'No calculation definition could price this scenario', [
            'reason_code' => 'no_matching_calculation_definition',
            'candidate_failures' => [[
                'definition_id' => 'definition-1',
                'reason' => 'missing_required_variables',
                'missing_variables' => ['extra_hours'],
            ]],
        ]);

        self::assertSame(
            'no_matching_calculation_definition',
            $result['calculation_metadata']['reason_code']
        );
        self::assertSame(
            ['extra_hours'],
            $result['calculation_metadata']['candidate_failures'][0]['missing_variables']
        );
    }
}
