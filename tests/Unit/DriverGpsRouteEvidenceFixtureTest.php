<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Support\DriverRouteEvidenceContract;

class DriverGpsRouteEvidenceFixtureTest extends TestCase
{
    #[Test]
    public function anonymized_incident_fixture_preserves_the_evidence_and_non_pricing_contract(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/Fixtures/driver_gps_route_evidence_incident.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $points = $fixture['raw_points'];
        $expected = $fixture['expected'];
        $current = array_values(array_filter(
            $points,
            fn (array $point): bool => $point['session_id'] === $fixture['session']['session_id']
        ));

        self::assertSame('anonymized-stale-recovery-gap-v1', $fixture['scenario_id']);
        self::assertCount($expected['recorded_point_count'], $points);
        self::assertCount($expected['accepted_point_count'], $current);
        self::assertSame(
            $expected['rejected_point_ids'],
            array_column(array_slice($points, 0, $expected['rejected_point_count']), 'point_id')
        );
        self::assertSame(
            $expected['longest_gap_seconds'],
            (int) CarbonImmutable::parse($points[3]['recorded_at'])->diffInSeconds(
                CarbonImmutable::parse($points[2]['recorded_at']),
                true
            )
        );
        self::assertFalse($expected['distance_trustworthy']);
        self::assertFalse($expected['legacy_distance_trustworthy']);
        self::assertSame('insufficient', $expected['coverage_status']);
        self::assertSame('none', $expected['pricing_effect']);
        self::assertContains('recorded_validated', DriverRouteEvidenceContract::CLASSIFICATIONS);
        self::assertContains($expected['rejection_reason'], DriverRouteEvidenceContract::REJECTION_REASONS);
        self::assertContains($expected['coverage_status'], DriverRouteEvidenceContract::COVERAGE_STATUSES);
        self::assertSame($expected['calculation_version'], DriverRouteEvidenceContract::CALCULATION_VERSION);
        self::assertSame($expected['pricing_effect'], DriverRouteEvidenceContract::PRICING_EFFECT);
    }
}
