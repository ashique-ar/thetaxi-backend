<?php

namespace Tests\Unit;

use App\Services\Driver\RouteEvidenceService;
use Carbon\Carbon;
use Tests\TestCase;

class TripTrackingDistanceTelemetryTest extends TestCase
{
    public function test_fewer_than_two_valid_points_is_unavailable_not_zero_distance(): void
    {
        $service = new RouteEvidenceService();
        $start = Carbon::parse('2026-09-01T11:00:00Z');
        $end = Carbon::parse('2026-09-01T11:10:00Z');

        self::assertNull($service->calculate(collect(), $start, $end)['recorded_distance_km']);
        self::assertNull($service->calculate(collect([$this->point('p1', '2026-09-01T11:01:00Z')]), $start, $end)['recorded_distance_km']);
    }

    public function test_stale_fixture_points_are_excluded_and_completion_gap_is_untrusted(): void
    {
        $service = new RouteEvidenceService();
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/driver_gps_route_evidence_incident.json')), true, flags: JSON_THROW_ON_ERROR);
        $points = collect($fixture['raw_points'])->map(fn (array $point) => $this->point(
            $point['point_id'], $point['recorded_at'], $point['latitude'], $point['longitude'], $point['accuracy']
        ));
        $result = $service->calculate($points, Carbon::parse($fixture['session']['started_at']), Carbon::parse('2026-09-01T16:13:21Z'));

        self::assertSame(12, $result['recorded_point_count']);
        self::assertSame(12, $result['valid_tracking_point_count']);
        self::assertSame(9, $result['accepted_point_count']);
        self::assertSame(0, $result['rejected_point_count']);
        self::assertSame(3, $result['outside_trip_window_point_count']);
        self::assertSame('partial', $result['coverage_status']);
        self::assertFalse($result['distance_trustworthy']);
        self::assertEqualsWithDelta(0.113, $result['recorded_distance_km'], 0.002);
        self::assertTrue($result['pricing_distance_eligible']);
        self::assertEqualsWithDelta(0.113, $result['pricing_distance_km'], 0.002);
        self::assertGreaterThan(300, $result['longest_gap_seconds']);
        self::assertSame('none', $result['pricing_effect']);
    }

    public function test_implausible_jump_is_not_summed(): void
    {
        $result = (new RouteEvidenceService())->calculate(collect([
            $this->point('p1', '2026-09-01T11:01:00Z', 7.2, 80.2),
            $this->point('p2', '2026-09-01T11:01:10Z', 8.2, 81.2),
        ]), Carbon::parse('2026-09-01T11:00:00Z'), Carbon::parse('2026-09-01T11:01:10Z'));

        self::assertNull($result['recorded_distance_km']);
        self::assertSame(1, $result['rejected_point_count']);
        self::assertSame(0, $result['outside_trip_window_point_count']);
        self::assertSame('insufficient', $result['coverage_status']);
        self::assertFalse($result['distance_trustworthy']);
        self::assertFalse($result['pricing_distance_eligible']);
    }

    public function test_exact_incident_gap_contributes_zero_distance(): void
    {
        $start = Carbon::parse('2026-09-01T00:00:00Z');
        $secondAt = $start->copy()->addSeconds(69436);
        $result = (new RouteEvidenceService())->calculate(collect([
            $this->point('p1', $start->toIso8601String(), 7.2, 80.2),
            $this->point('p2', $secondAt->toIso8601String(), 7.8, 80.8),
            $this->point('p3', $secondAt->copy()->addMinute()->toIso8601String(), 7.8001, 80.8001),
        ]), $start, $secondAt->copy()->addMinute());

        self::assertSame(69436, (int) $result['longest_gap_seconds']);
        self::assertGreaterThanOrEqual(1, $result['gap_count']);
        self::assertLessThan(0.1, $result['recorded_distance_km']);
        self::assertFalse($result['distance_trustworthy']);
        self::assertFalse($result['pricing_distance_eligible']);
        self::assertNull($result['pricing_distance_km']);
    }

    public function test_brief_gps_blackout_is_a_gap_and_does_not_bridge_movement(): void
    {
        $start = Carbon::parse('2026-09-01T11:00:00Z');
        $result = (new RouteEvidenceService())->calculate(collect([
            $this->point('before-off', '2026-09-01T11:00:01Z', 7.2, 80.2),
            $this->point('after-on', '2026-09-01T11:00:46Z', 7.25, 80.25),
            $this->point('continued', '2026-09-01T11:00:47Z', 7.25001, 80.25001),
        ]), $start, Carbon::parse('2026-09-01T11:00:47Z'));

        self::assertSame(1, $result['gap_count']);
        self::assertSame(45, (int) $result['longest_gap_seconds']);
        self::assertLessThan(0.01, $result['recorded_distance_km']);
        self::assertFalse($result['distance_trustworthy']);
        self::assertTrue($result['pricing_distance_eligible']);
        self::assertLessThan(0.01, $result['pricing_distance_km']);
        self::assertSame('none', $result['pricing_effect']);
    }

    public function test_points_before_start_and_after_completion_do_not_affect_distance(): void
    {
        $start = Carbon::parse('2026-09-01T11:00:00Z');
        $end = Carbon::parse('2026-09-01T11:05:00Z');
        $result = (new RouteEvidenceService())->calculate(collect([
            $this->point('before', '2026-09-01T10:59:00Z', 6.0, 79.0),
            $this->point('start', '2026-09-01T11:01:00Z', 7.2, 80.2),
            $this->point('end', '2026-09-01T11:02:00Z', 7.2001, 80.2001),
            $this->point('after', '2026-09-01T11:06:00Z', 8.0, 81.0),
        ]), $start, $end);

        self::assertSame(2, $result['accepted_point_count']);
        self::assertSame(4, $result['valid_tracking_point_count']);
        self::assertSame(0, $result['rejected_point_count']);
        self::assertSame(2, $result['outside_trip_window_point_count']);
        self::assertSame(0, $result['quality_rejected_point_count']);
        self::assertLessThan(0.1, $result['recorded_distance_km']);
    }

    public function test_invalid_point_is_rejected_even_when_it_is_outside_passenger_trip_phase(): void
    {
        $result = (new RouteEvidenceService())->calculate(collect([
            $this->point('invalid-before', '2026-09-01T10:59:00Z', 999, 79.0),
            $this->point('start', '2026-09-01T11:00:00Z', 7.2, 80.2),
            $this->point('next', '2026-09-01T11:00:10Z', 7.2001, 80.2001),
        ]), Carbon::parse('2026-09-01T11:00:00Z'), Carbon::parse('2026-09-01T11:00:10Z'));

        self::assertSame(2, $result['valid_tracking_point_count']);
        self::assertSame(1, $result['rejected_point_count']);
        self::assertSame(0, $result['outside_trip_window_point_count']);
    }

    private function point(string $id, string $recordedAt, float $latitude = 7.2, float $longitude = 80.2, ?float $accuracy = 8): object
    {
        return (object) ['id' => $id, 'recorded_at' => Carbon::parse($recordedAt), 'latitude' => $latitude, 'longitude' => $longitude, 'accuracy' => $accuracy];
    }
}
