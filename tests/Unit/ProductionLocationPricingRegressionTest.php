<?php

use App\Services\GoogleMapsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('routes airport coordinates through Routes API when a local IATA code occupies place_id', function () {
    config()->set('services.google.places_api_key', 'test-key');
    Cache::flush();
    Http::fake([
        'routes.googleapis.com/directions/v2:computeRoutes' => Http::response([
            'routes' => [['distanceMeters' => 10_000, 'duration' => '900s']],
        ]),
    ]);

    $result = app(GoogleMapsService::class)->distanceAndDuration(
        ['place_id' => 'CMB', 'latitude' => 7.1808, 'longitude' => 79.8841],
        ['latitude' => 6.9271, 'longitude' => 79.8612],
    );

    expect($result)->toBe(['distance_km' => 10.0, 'duration_seconds' => 900]);
    Http::assertSent(fn ($request) =>
        $request->url() === 'https://routes.googleapis.com/directions/v2:computeRoutes'
        && $request['origin']['location']['latLng'] === ['latitude' => 7.1808, 'longitude' => 79.8841]
        && $request['destination']['location']['latLng'] === ['latitude' => 6.9271, 'longitude' => 79.8612]
        && $request->hasHeader('X-Goog-FieldMask', 'routes.distanceMeters,routes.duration')
    );
});

it('fails route distance instead of returning zero when the routing API is unavailable', function () {
    config()->set('services.google.places_api_key', 'test-key');
    Cache::flush();
    Http::fake(['routes.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403)]);

    expect(fn () => app(GoogleMapsService::class)->distanceAndDuration(
        ['latitude' => 7.1808, 'longitude' => 79.8841],
        ['latitude' => 6.9271, 'longitude' => 79.8612],
    ))->toThrow(RuntimeException::class);
});

it('backs off after Google denies Routes API access', function () {
    config()->set('services.google.places_api_key', 'test-key');
    Cache::flush();
    Http::fake(['routes.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403)]);
    $maps = app(GoogleMapsService::class);
    $origin = ['latitude' => 7.1808, 'longitude' => 79.8841];
    $destination = ['latitude' => 6.9271, 'longitude' => 79.8612];

    expect(fn () => $maps->distanceAndDuration($origin, $destination))->toThrow(RuntimeException::class);
    expect(fn () => $maps->distanceAndDuration($origin, $destination))->toThrow(RuntimeException::class);

    Http::assertSentCount(1);
});

it('serves verified cached routes while Google access is in backoff', function () {
    config()->set('services.google.places_api_key', 'test-key');
    Cache::flush();
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => 10_000, 'duration' => '900s']]])]);
    $maps = app(GoogleMapsService::class);
    $origin = ['latitude' => 7.1808, 'longitude' => 79.8841];
    $destination = ['latitude' => 6.9271, 'longitude' => 79.8612];
    $verifiedRoute = ['distance_km' => 10.0, 'duration_seconds' => 900];

    expect($maps->distanceAndDuration($origin, $destination))->toBe($verifiedRoute);
    Cache::put('gm:routes:permission-denied:' . md5('test-key'), true, now()->addMinutes(5));

    expect($maps->distanceAndDuration($origin, $destination))->toBe($verifiedRoute);
    Http::assertSentCount(1);
});

it('keeps place detail database cache keys bounded', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/GooglePlacesController.php'));

    expect($source)
        ->toContain("'place_details:' . hash('sha256', \$placeId)")
        ->not->toContain('\"place_details:\" . $placeId');
});

it('does not price an incomplete draft without trips or vehicle groups', function () {
    $source = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect($source)
        ->toContain('if ($this->hasDraftPricingInputs($params))')
        ->toContain("|| !empty(\$params['booking_items'])");
});
