<?php

use App\Services\BookingOperationsHealthMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

it('retains the latest privacy-safe assignment health and the last issue', function () {
    Cache::flush();
    Log::spy();
    $monitor = app(BookingOperationsHealthMonitor::class);
    $context = [
        'driver_id' => 'driver-1',
        'assignment_id' => 'assignment-1',
        'booking_id' => 'booking-1',
        'booking_item_id' => 'item-1',
    ];

    $monitor->recordDriverTrackingHealth($context, [
        'state' => 'queue_pressure',
        'queue_count' => 1200,
        'oldest_queue_age_seconds' => 90,
        'latitude' => 6.9271,
        'longitude' => 79.8612,
    ]);
    $monitor->recordDriverTrackingHealth($context, [
        'state' => 'recovered',
        'queue_count' => 0,
    ]);

    $health = $monitor->latestDriverTrackingHealth('assignment-1');
    expect($health)
        ->state->toBe('recovered')
        ->queue_count->toBe(0)
        ->last_issue->state->toBe('queue_pressure')
        ->not->toHaveKey('latitude')
        ->not->toHaveKey('longitude');
});

it('projects driver health through tracking summary without changing pricing', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/BookingObservabilityController.php'));
    $interface = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/interfaces/booking-observability.interface.ts'));

    expect($controller)->toContain("\$data['tracking_health']")
        ->and($interface)->toContain('tracking_health?: BookingDriverTrackingHealth | null')
        ->and($interface)->toContain("pricing_effect: 'none'");
});
