<?php

use App\Services\GoogleMapsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('uses airport coordinates when a local IATA code occupies place_id', function () {
    config()->set('services.google.places_api_key', 'test-key');
    Cache::flush();
    Http::fake([
        'maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
            'status' => 'OK',
            'rows' => [[
                'elements' => [[
                    'status' => 'OK',
                    'distance' => ['value' => 10_000],
                    'duration' => ['value' => 900],
                ]],
            ]],
        ]),
    ]);

    app(GoogleMapsService::class)->distanceAndDuration(
        ['place_id' => 'CMB', 'latitude' => 7.1808, 'longitude' => 79.8841],
        ['latitude' => 6.9271, 'longitude' => 79.8612],
    );

    Http::assertSent(fn ($request) =>
        $request['origins'] === '7.1808,79.8841'
        && $request['destinations'] === '6.9271,79.8612'
    );
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
