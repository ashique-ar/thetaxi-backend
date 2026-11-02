<?php

namespace Tests\Unit\Tours;

use App\Services\GoogleMapsService;
use App\Services\Tours\TourRoutePlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TourRoutePlannerTest extends TestCase
{
    public function test_reorders_backtracking_without_losing_the_off_highway_visit_or_adding_a_return(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturnCallback(
            fn (array $from, array $to): array => [
                'distance_km' => abs($from['lat'] - $to['lat']),
                'duration_seconds' => (int) (abs($from['lat'] - $to['lat']) * 60),
            ]
        );
        $stops = [$this->stop('pickup', 0), $this->stop('far', 8), $this->stop('activity-entrance', 2), $this->stop('dropoff', 10)];

        $result = (new TourRoutePlanner($maps))->suggest($stops);

        self::assertSame(['pickup', 'activity-entrance', 'far', 'dropoff'], array_column($result['suggested']['stops'] ?? [], 'id'));
        self::assertSame('ready', $result['status']);
        self::assertTrue($result['changed']);
        self::assertSame(22.0, $result['original']['distance_km']);
        self::assertSame(10.0, $result['suggested']['distance_km']);
        self::assertSame(600, $result['suggested']['duration_seconds']);
        self::assertCount(3, $result['suggested']['legs']);
        self::assertSame('activity-entrance', $result['suggested']['legs'][0]['to_stop_id']);
    }

    public function test_two_opt_reduces_backtracking_that_no_single_adjacent_swap_can_improve(): void
    {
        $roads = [
            [8, 3, 8, 38, 25, 50],
            [34, 31, 18, 33, 24, 14],
            [19, 33, 42, 45, 30, 1],
            [12, 9, 26, 14, 10, 47],
            [23, 42, 28, 16, 36, 35],
            [1, 6, 19, 33, 15, 18],
        ];
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturnCallback(fn (array $from, array $to): array => [
            'distance_km' => $roads[(int) $from['lat']][(int) $to['lat']],
            'duration_seconds' => 60,
        ]);
        $ids = ['pickup', 'visit-a', 'activity-access', 'visit-b', 'visit-c', 'dropoff'];
        $stops = array_map(fn (string $id, int $index): array => $this->stop($id, (float) $index), $ids, array_keys($ids));

        $result = (new TourRoutePlanner($maps))->suggest($stops);

        self::assertSame(111.0, $result['original']['distance_km']);
        self::assertLessThan(111.0, $result['suggested']['distance_km']);
        self::assertSame('pickup', $result['suggested']['stops'][0]['id']);
        self::assertSame('dropoff', $result['suggested']['stops'][5]['id']);
        self::assertEqualsCanonicalizing($ids, array_column($result['suggested']['stops'], 'id'));
        self::assertContains('activity-access', array_column($result['suggested']['stops'], 'id'));
    }

    private function stop(string $id, float $latitude, array $extra = []): array
    {
        return $extra + ['id' => $id, 'latitude' => $latitude, 'longitude' => 80.0, 'visit_minutes' => 0];
    }

    public function test_keeps_locked_visits_in_place(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturnCallback(fn (array $from, array $to): array => [
            'distance_km' => abs($from['lat'] - $to['lat']), 'duration_seconds' => 60,
        ]);
        $stops = [$this->stop('pickup', 0), $this->stop('locked-activity', 8, ['locked' => true]), $this->stop('near', 2), $this->stop('dropoff', 10)];

        $result = (new TourRoutePlanner($maps))->suggest($stops);

        self::assertSame($stops, $result['suggested']['stops']);
        self::assertFalse($result['changed']);
    }

    public function test_shorter_distance_that_takes_longer_is_not_recommended(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturnCallback(fn (array $from, array $to): array => [
            'distance_km' => abs($from['lat'] - $to['lat']),
            'duration_seconds' => $from['lat'] === 0.0 && $to['lat'] === 2.0 ? 1000 : 60,
        ]);
        $stops = [$this->stop('pickup', 0), $this->stop('far', 8), $this->stop('near', 2), $this->stop('dropoff', 10)];

        $result = (new TourRoutePlanner($maps))->suggest($stops);

        self::assertSame($stops, $result['suggested']['stops']);
        self::assertFalse($result['changed']);
    }

    public function test_uses_directed_road_legs_and_caches_each_direction_only_within_one_suggestion(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $calls = [];
        $roads = ['0:8' => 8, '8:2' => 6, '2:10' => 8, '0:2' => 2, '2:8' => 50, '8:10' => 2];
        $maps->method('distanceAndDuration')->willReturnCallback(function (array $from, array $to) use (&$calls, $roads): array {
            $key = $from['lat'].':'.$to['lat'];
            $calls[$key] = ($calls[$key] ?? 0) + 1;

            return ['distance_km' => $roads[$key], 'duration_seconds' => 60];
        });
        $planner = new TourRoutePlanner($maps);
        $stops = [$this->stop('pickup', 0), $this->stop('far', 8), $this->stop('near', 2), $this->stop('dropoff', 10)];

        $first = $planner->suggest($stops);
        $second = $planner->suggest($stops);

        self::assertFalse($first['changed']);
        self::assertSame($first, $second);
        self::assertSame(22.0, $first['suggested']['distance_km']);
        self::assertSame([2], array_values(array_unique($calls)));
        self::assertArrayHasKey('8:2', $calls);
        self::assertArrayHasKey('2:8', $calls);
    }

    public function test_shared_access_point_keeps_both_visits_and_their_durations(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->expects(self::exactly(2))->method('distanceAndDuration')->willReturn(['distance_km' => 5, 'duration_seconds' => 300]);
        $stops = [$this->stop('pickup', 0), $this->stop('activity-a', 2, ['visit_minutes' => 30]), $this->stop('activity-b', 2, ['visit_minutes' => 45]), $this->stop('dropoff', 10)];

        $result = (new TourRoutePlanner($maps))->suggest($stops);

        self::assertSame($stops, $result['suggested']['stops']);
        self::assertSame(0.0, $result['suggested']['legs'][1]['distance_km']);
        self::assertSame(4500, $result['suggested']['visit_duration_seconds']);
        self::assertSame(5100, $result['suggested']['total_duration_seconds']);
    }

    public function test_a_late_fixed_arrival_requires_review_and_the_stop_does_not_move(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturn(['distance_km' => 5, 'duration_seconds' => 300]);
        $stops = [$this->stop('pickup', 0), $this->stop('timed-activity', 8, ['arrival_time' => '09:00']), $this->stop('near', 2), $this->stop('dropoff', 10)];

        $result = (new TourRoutePlanner($maps))->suggest($stops, '09:00');

        self::assertSame('review_required', $result['status']);
        self::assertSame($stops, $result['suggested']['stops']);
        self::assertSame('late_arrival', $result['issues'][0]['code']);
    }

    public function test_provider_failure_preserves_visits_and_never_returns_zero_distance_as_success(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willThrowException(new RuntimeException('Upstream unavailable'));
        $stops = [$this->stop('pickup', 0), $this->stop('activity', 2), $this->stop('dropoff', 10)];

        $result = (new TourRoutePlanner($maps))->suggest($stops);

        self::assertSame('unavailable', $result['status']);
        self::assertFalse($result['changed']);
        self::assertSame($stops, $result['suggested']['stops']);
        self::assertNull($result['original']['distance_km']);
        self::assertNull($result['suggested']['duration_seconds']);
        self::assertSame('route_unavailable', $result['issues'][0]['code']);
    }

    public function test_invalid_provider_distance_requires_review_instead_of_accepting_a_priceable_route(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturn(['distance_km' => -1, 'duration_seconds' => 0]);

        $result = (new TourRoutePlanner($maps))->suggest([$this->stop('pickup', 0), $this->stop('dropoff', 10)]);

        self::assertSame('unavailable', $result['status']);
        self::assertNull($result['suggested']['distance_km']);
    }

    public function test_duplicate_visit_ids_are_rejected_before_calling_the_provider(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->expects(self::never())->method('distanceAndDuration');
        $this->expectException(InvalidArgumentException::class);

        (new TourRoutePlanner($maps))->suggest([$this->stop('same-id', 0), $this->stop('same-id', 10)]);
    }

    public function test_invalid_coordinates_are_rejected_before_calling_the_provider(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->expects(self::never())->method('distanceAndDuration');
        $this->expectException(InvalidArgumentException::class);

        (new TourRoutePlanner($maps))->suggest([$this->stop('pickup', 0), $this->stop('bad-activity', 95)]);
    }

    public function test_improves_flexible_spans_without_crossing_a_locked_anchor(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturnCallback(fn (array $from, array $to): array => [
            'distance_km' => abs($from['lat'] - $to['lat']),
            'duration_seconds' => (int) (abs($from['lat'] - $to['lat']) * 60),
        ]);
        $stops = [$this->stop('pickup', 0), $this->stop('far-a', 8), $this->stop('near-a', 2), $this->stop('fixed', 10, ['locked' => true]), $this->stop('far-b', 18), $this->stop('near-b', 12), $this->stop('dropoff', 20)];

        $result = (new TourRoutePlanner($maps))->suggest($stops);

        self::assertSame(['pickup', 'near-a', 'far-a', 'fixed', 'near-b', 'far-b', 'dropoff'], array_column($result['suggested']['stops'], 'id'));
        self::assertSame(20.0, $result['suggested']['distance_km']);
    }

    public function test_a_loop_keeps_the_return_anchor_and_visit_metadata(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturn(['distance_km' => 5, 'duration_seconds' => 300]);
        $stops = [$this->stop('pickup', 0), $this->stop('activity', 2, ['activity_id' => 'catalogue-activity', 'day' => 1]), $this->stop('return', 0)];

        $result = (new TourRoutePlanner($maps))->suggest($stops);

        self::assertSame($stops, $result['suggested']['stops']);
        self::assertSame('return', $result['suggested']['legs'][1]['to_stop_id']);
        self::assertSame(10.0, $result['suggested']['distance_km']);
    }

    public function test_failure_while_exploring_a_candidate_does_not_publish_a_partial_suggestion(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturnCallback(function (array $from, array $to): array {
            if ($from['lat'] === 2.0 && $to['lat'] === 8.0) {
                throw new RuntimeException('Candidate route unavailable');
            }

            return ['distance_km' => 5, 'duration_seconds' => 300];
        });
        $stops = [$this->stop('pickup', 0), $this->stop('far', 8), $this->stop('near', 2), $this->stop('dropoff', 10)];

        $result = (new TourRoutePlanner($maps))->suggest($stops);

        self::assertSame('unavailable', $result['status']);
        self::assertSame($stops, $result['suggested']['stops']);
        self::assertNull($result['suggested']['distance_km']);
        self::assertFalse($result['changed']);
    }

    public function test_waits_until_activity_opens_and_counts_the_wait_in_the_schedule(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturn(['distance_km' => 5, 'duration_seconds' => 300]);
        $stops = [$this->stop('pickup', 0), $this->stop('activity', 2, ['opens_at' => '10:00', 'closes_at' => '16:00', 'visit_minutes' => 30]), $this->stop('dropoff', 10)];

        $result = (new TourRoutePlanner($maps))->suggest($stops, '09:00');

        self::assertSame('ready', $result['status']);
        self::assertSame(3300, $result['suggested']['waiting_duration_seconds']);
        self::assertSame(5700, $result['suggested']['total_duration_seconds']);
        self::assertSame('10:00', $result['suggested']['schedule'][1]['estimated_arrival']);
        self::assertSame([], $result['issues']);
    }

    public function test_reorders_flexible_visits_to_fit_activity_opening_hours(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturnCallback(fn (array $from, array $to): array => [
            'distance_km' => abs($from['lat'] - $to['lat']), 'duration_seconds' => 300,
        ]);
        $stops = [
            $this->stop('pickup', 0),
            $this->stop('late-activity', 2, ['opens_at' => '10:00', 'closes_at' => '10:30', 'visit_minutes' => 30]),
            $this->stop('morning-activity', 3, ['opens_at' => '09:00', 'closes_at' => '09:30', 'visit_minutes' => 10]),
            $this->stop('dropoff', 4),
        ];

        $result = (new TourRoutePlanner($maps))->suggest($stops, '09:00');

        self::assertSame('ready', $result['status']);
        self::assertSame(['pickup', 'morning-activity', 'late-activity', 'dropoff'], array_column($result['suggested']['stops'], 'id'));
        self::assertTrue($result['changed']);
    }

    public function test_visit_that_would_finish_after_closing_requires_review(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturn(['distance_km' => 5, 'duration_seconds' => 300]);
        $stops = [$this->stop('pickup', 0), $this->stop('activity', 2, ['opens_at' => '09:00', 'closes_at' => '09:20', 'visit_minutes' => 30]), $this->stop('dropoff', 10)];

        $result = (new TourRoutePlanner($maps))->suggest($stops, '09:00');

        self::assertSame('review_required', $result['status']);
        self::assertSame('outside_opening_hours', $result['issues'][0]['code']);
    }

    public function test_a_route_that_runs_past_midnight_requires_review(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->method('distanceAndDuration')->willReturn(['distance_km' => 5, 'duration_seconds' => 13 * 3600]);
        $result = (new TourRoutePlanner($maps))->suggest([$this->stop('pickup', 0), $this->stop('activity', 2), $this->stop('dropoff', 10)], '09:00');

        self::assertSame('review_required', $result['status']);
        self::assertSame('day_duration_exceeded', $result['issues'][0]['code']);
    }

    public function test_negative_visit_duration_is_rejected_before_calling_the_provider(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->expects(self::never())->method('distanceAndDuration');
        $this->expectException(InvalidArgumentException::class);

        (new TourRoutePlanner($maps))->suggest([$this->stop('pickup', 0), $this->stop('activity', 2, ['visit_minutes' => -1]), $this->stop('dropoff', 10)]);
    }

    public function test_non_finite_coordinates_are_rejected_before_calling_the_provider(): void
    {
        $maps = $this->createMock(GoogleMapsService::class);
        $maps->expects(self::never())->method('distanceAndDuration');
        $this->expectException(InvalidArgumentException::class);

        (new TourRoutePlanner($maps))->suggest([$this->stop('pickup', 0), $this->stop('bad-activity', INF)]);
    }
}
