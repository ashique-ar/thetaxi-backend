<?php

use App\Http\Controllers\Api\Corporate\CorporateBookingController;
use App\Services\CorporateBookingService;

uses(Tests\TestCase::class);

it('creates an audited replacement decision while retaining the prior contractual distances', function () {
    $controller = new CorporateBookingController(Mockery::mock(CorporateBookingService::class));
    $method = new ReflectionMethod($controller, 'applyContractualDistanceOverrideToSnapshot');
    $snapshot = [
        'base_pricing' => [
            'distance_policy' => ['coordinate_source' => 'corporate_distance_policy'],
            'distance_details' => [
                'origin_to_pickup_distance' => 10,
                'journey_distance' => 2,
                'dropoff_to_return_distance' => 12,
                'total_billable_distance' => 24,
            ],
        ],
    ];
    $result = $method->invoke($controller, $snapshot, [
        'reason' => 'Approved correction after contractual review',
        'origin_to_pickup_distance' => 8,
        'journey_distance' => 3,
        'dropoff_to_return_distance' => 9,
        'total_billable_distance' => 20,
        'movement_charge' => 400,
    ], 'reviewer-1');

    expect(data_get($result, 'base_pricing.distance_details.total_billable_distance'))->toBe(20.0)
        ->and(data_get($result, 'base_pricing.manual_override_history.0.actor_id'))->toBe('reviewer-1')
        ->and(data_get($result, 'base_pricing.manual_override_history.0.before.total_billable_distance'))->toBe(24)
        ->and(data_get($result, 'base_pricing.manual_override_history.0.after.total_billable_distance'))->toBe(20.0)
        ->and(data_get($result, 'base_pricing.distance_policy.coordinate_source'))->toBe('corporate_distance_policy');
});

it('keeps corporate live progress minimal time bounded and separate from raw tracking', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateBookingController.php'));
    $live = Str::between($controller, 'public function liveProgress(', 'public function overrideContractualDistance(');
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::get('bookings/{id}/live-progress'")
        ->toContain("Route::post('bookings/{id}/contractual-distance-override'")
        ->toContain("->middleware('permission:approve_bookings')")
        ->and($live)
        ->toContain("'raw_tracking' => false")
        ->toContain('addHours(2)')
        ->toContain('subMinutes(5)')
        ->not->toContain('RoutePoint')
        ->not->toContain('routePoints')
        ->not->toContain('history')
        ->not->toContain('session');
});
