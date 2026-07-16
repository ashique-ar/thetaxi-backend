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

    public function test_legacy_pricing_types_cannot_be_silently_reported_as_healthy(): void
    {
        foreach (['flat_rate', 'per_km'] as $type) {
            $health = $this->service->analyze([[
                'id' => "legacy-{$type}",
                'service_type_id' => 'service-1',
                'name' => "Legacy {$type}",
                'type' => $type,
                'min_hours' => 1,
                'max_hours' => 6,
                'is_active' => true,
            ]]);

            $participatesInResolution = collect(
                $health['services']['service-1']['units'] ?? []
            )->contains(fn (array $unit) => !empty($unit['ranges']));

            $this->assertTrue(
                $participatesInResolution || !$health['healthy'],
                "{$type} is accepted and seeded, but it neither participates in runtime slab resolution nor blocks activation."
            );
        }
    }

    public function test_discrete_legacy_flat_rate_packages_can_have_intentional_gaps(): void
    {
        $health = $this->service->analyze([
            $this->slab('six-hours', 'flat_rate', 6, 6),
            $this->slab('eight-hours', 'flat_rate', 8, 8),
            $this->slab('twelve-hours', 'flat_rate', 12, 12),
        ]);

        $this->assertTrue($health['healthy']);
        $this->assertCount(3, $health['services']['service-1']['units']['flat_rate']['ranges']);
    }

    public function test_overlapping_legacy_per_km_ranges_are_ambiguous(): void
    {
        $health = $this->service->analyze([
            $this->slab('one-way', 'per_km', 1, 6),
            $this->slab('round-trip', 'per_km', 2, 12),
        ]);

        $this->assertFalse($health['healthy']);
        $this->assertContains('overlap', array_column($health['issues'], 'code'));
    }

    public function test_one_range_less_legacy_type_is_an_explicit_resolvable_fallback(): void
    {
        $health = $this->service->analyze([[
            'id' => 'fallback',
            'service_type_id' => 'service-1',
            'name' => 'Distance fallback',
            'type' => 'per_km',
            'is_active' => true,
        ]]);

        $range = $health['services']['service-1']['units']['per_km']['ranges'][0];
        $this->assertTrue($health['healthy']);
        $this->assertTrue($range['fallback']);
        $this->assertSame(0, $range['canonical_min_minutes']);
        $this->assertNull($range['canonical_max_minutes']);
    }

    public function test_duplicate_range_less_fallbacks_are_blocking(): void
    {
        $health = $this->service->analyze([
            ['id' => 'first', 'service_type_id' => 'service-1', 'name' => 'First', 'type' => 'flat_rate'],
            ['id' => 'second', 'service_type_id' => 'service-1', 'name' => 'Second', 'type' => 'flat_rate'],
        ]);

        $this->assertFalse($health['healthy']);
        $this->assertContains(
            'duplicate_duration_independent_fallback',
            array_column($health['issues'], 'code')
        );
    }

    public function test_unsafe_leading_duration_gap_is_blocking_without_another_resolver(): void
    {
        $health = $this->service->analyze([
            $this->slab('late-minute-start', 'minutes', 30, null),
        ]);

        $this->assertFalse($health['healthy']);
        $this->assertContains('unsafe_leading_gap', array_column($health['issues'], 'code'));
    }

    public function test_higher_and_lower_units_can_safely_cover_a_leading_unit_gap(): void
    {
        $health = $this->service->analyze([
            $this->slab('minute-window', 'minutes', 30, 60),
            $this->slab('hour-fallback', 'hours', 1, null),
        ]);

        $this->assertTrue($health['healthy']);
        $this->assertNotContains('unsafe_leading_gap', array_column($health['issues'], 'code'));
    }

    public function test_completely_shadowed_cross_unit_range_is_blocking(): void
    {
        $health = $this->service->analyze([
            $this->slab('all-hours', 'hours', 1, null),
            $this->slab('six-hour-package', 'flat_rate', 6, 6),
        ]);

        $this->assertFalse($health['healthy']);
        $this->assertContains('cross_unit_fully_shadowed', array_column($health['issues'], 'code'));
    }

    /** @return array<string, mixed> */
    private function slab(string $id, string $type, int $minimum, ?int $maximum): array
    {
        $range = match ($type) {
            'minutes' => ['min_minutes' => $minimum, 'max_minutes' => $maximum],
            'hours', 'flat_rate', 'per_km' => ['min_hours' => $minimum, 'max_hours' => $maximum],
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
