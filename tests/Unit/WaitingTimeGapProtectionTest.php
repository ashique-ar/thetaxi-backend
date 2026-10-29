<?php

use App\Models\Driver\RoutePoint;
use App\Services\Driver\WaitingTimeService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

it('does not join stationary GPS fixes across a long gap', function () {
    $start = Carbon::parse('2026-10-29 08:00:00 UTC');
    $points = new Collection(collect([0, 30, 120, 150, 180])->map(
        fn (int $seconds) => new RoutePoint([
            'latitude' => 6.9,
            'longitude' => 79.8,
            'speed' => 0,
            'recorded_at' => $start->copy()->addSeconds($seconds),
        ])
    )->all());

    expect((new WaitingTimeService())->detectStationaryPeriod($points))->toBeNull();
});
