<?php

use App\Models\Driver\RoutePoint;
use App\Services\BookingObservabilityService;
use App\Services\Driver\RouteEvidenceService;
use Illuminate\Support\Collection;

uses(Tests\TestCase::class);

it('breaks replay lines around implausible coordinate jumps', function () {
    $points = collect([
        new RoutePoint(['id' => 'point-1', 'latitude' => 6.9271, 'longitude' => 79.8612, 'accuracy' => 5, 'recorded_at' => '2026-09-14 10:00:00']),
        new RoutePoint(['id' => 'point-2', 'latitude' => 7.9271, 'longitude' => 80.8612, 'accuracy' => 5, 'recorded_at' => '2026-09-14 10:00:10']),
        new RoutePoint(['id' => 'point-3', 'latitude' => 6.9272, 'longitude' => 79.8613, 'accuracy' => 5, 'recorded_at' => '2026-09-14 10:00:20']),
    ]);
    $service = new BookingObservabilityService(new RouteEvidenceService());
    $method = new ReflectionMethod($service, 'segmentRoutePoints');
    [, $segments, $quality] = $method->invoke($service, $points, new Collection(), new Collection());

    expect($segments)->toHaveCount(3)
        ->and($segments[1]['quality_flags'])->toContain('implausible_movement')
        ->and($segments[2]['quality_flags'])->toContain('implausible_movement')
        ->and($quality['implausible_speed_count'])->toBe(2)
        ->and($quality['operational_distance_km'])->toBe(0.0);
});
