<?php

namespace Tests\Unit;

use App\Services\VehiclePricingSlabConfigurationService;
use PHPUnit\Framework\TestCase;

class VehiclePricingSlabConfigurationServiceTest extends TestCase
{
    private VehiclePricingSlabConfigurationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VehiclePricingSlabConfigurationService();
    }

    public function test_duration_units_have_deterministic_precedence_and_rounding(): void
    {
        $this->assertSame(
            ['minutes', 'hours', 'days', 'per_day'],
            VehiclePricingSlabConfigurationService::DURATION_PRECEDENCE
        );
        $this->assertSame(61.0, $this->service->durationValue('minutes', 61));
        $this->assertSame(2.0, $this->service->durationValue('hours', 61));
        $this->assertSame(1.0, $this->service->durationValue('days', 61));
        $this->assertSame(2.0, $this->service->durationValue('per_day', 61, 2));
    }

    public function test_adjacent_hour_buckets_are_canonicalized_without_fractional_gaps(): void
    {
        $health = $this->service->analyze([
            $this->slab('first', 'hours', 1, 2),
            $this->slab('second', 'hours', 3, null),
        ]);

        $ranges = $health['services']['service-1']['units']['hours']['ranges'];

        $this->assertTrue($health['healthy']);
        $this->assertSame(1, $ranges[0]['canonical_min_minutes']);
        $this->assertSame(120, $ranges[0]['canonical_max_minutes']);
        $this->assertSame(121, $ranges[1]['canonical_min_minutes']);
        $this->assertNull($ranges[1]['canonical_max_minutes']);
    }

    public function test_same_unit_gap_is_a_blocking_error(): void
    {
        $health = $this->service->analyze([
            $this->slab('first', 'minutes', 0, 60),
            $this->slab('second', 'minutes', 62, null),
        ]);

        $this->assertFalse($health['healthy']);
        $this->assertContains('gap', array_column($health['issues'], 'code'));
        $this->assertTrue($this->service->hasBlockingIssues($health, [[
            'service_type_id' => 'service-1',
            'type' => 'minutes',
        ]]));
    }

    public function test_same_unit_overlap_is_a_blocking_error(): void
    {
        $health = $this->service->analyze([
            $this->slab('first', 'days', 1, 3),
            $this->slab('second', 'days', 3, 5),
        ]);

        $this->assertFalse($health['healthy']);
        $this->assertContains('overlap', array_column($health['issues'], 'code'));
    }

    public function test_duplicate_open_ended_slabs_are_reported_explicitly(): void
    {
        $health = $this->service->analyze([
            $this->slab('first', 'per_day', 1, null),
            $this->slab('second', 'per_day', 2, null),
        ]);

        $this->assertFalse($health['healthy']);
        $this->assertContains('duplicate_open_ended', array_column($health['issues'], 'code'));
    }

    public function test_cross_unit_ranges_are_valid_because_precedence_resolves_them(): void
    {
        $health = $this->service->analyze([
            $this->slab('minute', 'minutes', 0, 60),
            $this->slab('hour', 'hours', 1, 24),
            $this->slab('day', 'days', 1, null),
        ]);

        $this->assertTrue($health['healthy']);
        $this->assertSame(
            ['minutes', 'hours', 'days', 'per_day'],
            $health['precedence']
        );
        $this->assertSame(0, $health['summary']['errors']);
    }

    /** @return array<string, mixed> */
    private function slab(string $id, string $type, int $minimum, ?int $maximum): array
    {
        $range = match ($type) {
            'minutes' => ['min_minutes' => $minimum, 'max_minutes' => $maximum],
            'hours' => ['min_hours' => $minimum, 'max_hours' => $maximum],
            default => ['min_days' => $minimum, 'max_days' => $maximum],
        };

        return $range + [
            'id' => $id,
            'service_type_id' => 'service-1',
            'name' => ucfirst($id),
            'type' => $type,
            'is_active' => true,
        ];
    }
}
