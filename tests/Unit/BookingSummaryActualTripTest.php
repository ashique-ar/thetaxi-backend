<?php

it('keeps actual trip measurements separate from estimates and preserves zero waiting', function () {
    $controller = (new ReflectionClass(\App\Http\Controllers\Api\AssignmentController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($controller, 'buildPricingMetrics');
    $item = new \Illuminate\Support\Fluent([
        'pricing_breakdown' => ['distance_details' => ['journey_distance' => 99], 'waiting_minutes' => 45],
        'duration_minutes' => 120,
    ]);
    $missing = $method->invoke($controller, $item, null);
    expect($missing['actual_distance_km'])->toBeNull()
        ->and($missing['actual_waiting_minutes'])->toBeNull()
        ->and($missing['actual_duration_minutes'])->toBeNull()
        ->and($missing['actual_start_at'])->toBeNull();

    $zero = $method->invoke($controller, $item, new \Illuminate\Support\Fluent(['total_waiting_time_seconds' => 0]));
    expect($zero['actual_waiting_minutes'])->toBe(0.0)
        ->and($zero['total_waiting_seconds'])->toBe(0);

    $assignment = new \Illuminate\Support\Fluent([
        'trip_started_at' => \Carbon\Carbon::parse('2026-09-12T08:00:00Z'),
        'trip_completed_at' => \Carbon\Carbon::parse('2026-09-12T09:30:00Z'),
        'total_distance_km' => 32.5,
        'total_waiting_time_seconds' => 3723,
        'pickup_waiting_time_seconds' => 61,
        'hire_waiting_time_seconds' => 3662,
    ]);
    $actual = $method->invoke($controller, $item, $assignment);
    expect($actual['actual_distance_km'])->toBe(32.5)
        ->and($actual['actual_waiting_minutes'])->toBe(62.05)
        ->and($actual['total_waiting_seconds'])->toBe(3723)
        ->and($actual['pickup_waiting_seconds'])->toBe(61)
        ->and($actual['hire_waiting_seconds'])->toBe(3662)
        ->and($actual['actual_duration_minutes'])->toBe(90.0)
        ->and($actual['actual_start_at'])->toBe('2026-09-12T08:00:00+00:00')
        ->and($actual['actual_end_at'])->toBe('2026-09-12T09:30:00+00:00');

    $item->pricing_breakdown = ['final_pricing' => ['audit' => ['inputs' => ['distance_km' => 40, 'total_waiting_minutes' => 12, 'duration_minutes' => 100]]]];
    $final = $method->invoke($controller, $item, null);
    expect($final['actual_distance_km'])->toBe(40.0)
        ->and($final['actual_waiting_minutes'])->toBe(12.0)
        ->and($final['actual_duration_minutes'])->toBe(100.0);
});
