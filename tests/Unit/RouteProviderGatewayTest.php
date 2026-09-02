<?php

use App\Models\Booking\BookingItem;
use App\Services\Driver\RouteProviderGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Cache::flush();
    config()->set('route_evidence.estimates_enabled', true);
    config()->set('route_evidence.osrm.enabled', true);
    config()->set('route_evidence.osrm.base_url', 'https://osrm.test');
});

it('uses osrm first and reuses an idempotent non-pricing estimate', function () {
    Http::fake(['https://osrm.test/*' => Http::response([
        'routes' => [['distance' => 1234.5, 'duration' => 321, 'geometry' => 'encoded']],
    ])]);
    $item = new BookingItem([
        'pickup_latitude' => 6.9271, 'pickup_longitude' => 79.8612,
        'dropoff_latitude' => 6.9147, 'dropoff_longitude' => 79.9729,
    ]);
    $item->id = '11111111-1111-4111-8111-111111111111';
    $gateway = app(RouteProviderGateway::class);

    $first = $gateway->estimate($item, 'operational_gap_estimate', 'auto', '22222222-2222-4222-8222-222222222222', false);
    $second = $gateway->estimate($item, 'operational_gap_estimate', 'auto', '22222222-2222-4222-8222-222222222222', false);

    expect($first)->provider->toBe('osrm')
        ->classification->toBe('estimated_route')
        ->confidence->toBe('low')
        ->pricing_effect->toBe('none')
        ->and($second)->toBe($first);
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'googleapis.com'));
});

it('blocks google unless explicitly requested confirmed enabled and budgeted', function () {
    config()->set('route_evidence.google.enabled', false);
    Http::fake();
    $item = new BookingItem([
        'pickup_latitude' => 6.9271, 'pickup_longitude' => 79.8612,
        'dropoff_latitude' => 6.9147, 'dropoff_longitude' => 79.9729,
    ]);
    $item->id = '11111111-1111-4111-8111-111111111111';

    expect(fn () => app(RouteProviderGateway::class)->estimate(
        $item, 'operational_gap_estimate', 'google', '33333333-3333-4333-8333-333333333333', false
    ))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

it('allows one explicitly confirmed budgeted google call and replays its idempotent result', function () {
    config()->set('route_evidence.google.enabled', true);
    config()->set('route_evidence.google.api_key', 'test-key');
    config()->set('route_evidence.google.daily_budget', 1);
    config()->set('route_evidence.google.monthly_budget', 1);
    Http::fake(['https://routes.googleapis.com/*' => Http::response([
        'routes' => [[
            'distanceMeters' => 2100,
            'duration' => '420s',
            'polyline' => ['encodedPolyline' => 'encoded-google'],
        ]],
    ])]);
    $item = new BookingItem([
        'pickup_latitude' => 6.9271, 'pickup_longitude' => 79.8612,
        'dropoff_latitude' => 6.9147, 'dropoff_longitude' => 79.9729,
    ]);
    $item->id = '11111111-1111-4111-8111-111111111111';
    $gateway = app(RouteProviderGateway::class);
    $requestId = '44444444-4444-4444-8444-444444444444';

    $first = $gateway->estimate($item, 'operational_gap_estimate', 'google', $requestId, true);
    $second = $gateway->estimate($item, 'operational_gap_estimate', 'google', $requestId, true);

    expect($first)->provider->toBe('google')->pricing_effect->toBe('none')
        ->and($second)->toBe($first);
    Http::assertSentCount(1);
});

it('keeps provider calls out of tracking summary rendering', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/booking-management/booking-management.component.ts'));
    expect($component)->not->toContain('buildTrackingFallbackPath')
        ->not->toContain('TripDistanceService')
        ->toContain('generateOperationalRouteEstimate');
});

it('protects the estimate command with its dedicated permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    expect($routes)->toContain("Route::post('route-estimates'")
        ->toContain("permission:bookings.tracking_estimate");
});
