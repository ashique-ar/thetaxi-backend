<?php

use App\Services\BookingFlowService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('checks airport proximity without making Google route requests', function () {
    config()->set('services.google.maps_api_key', 'test-key');
    Cache::flush();
    Http::fake();
    $flow = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
    $isAirport = new ReflectionMethod(BookingFlowService::class, 'isAirportLocation');

    expect($isAirport->invoke($flow, ['latitude' => 7.1808, 'longitude' => 79.8841]))->toBeTrue()
        ->and($isAirport->invoke($flow, ['latitude' => 6.9271, 'longitude' => 79.8612]))->toBeFalse();

    Http::assertNothingSent();
});

