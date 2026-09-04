<?php

use App\Http\Controllers\Api\BookingRouteUsageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


it('records only normalized booking route evidence when pilot collection is enabled', function () {
    config()->set('booking_observability.route_usage', [
        'enabled' => true,
        'starts_at' => now()->subMinute()->toIso8601String(),
        'ends_at' => now()->addMinute()->toIso8601String(),
    ]);
    $actor = new class {
        public function getAuthIdentifier(): string { return 'actor-1'; }
    };
    $request = Request::create('/api/bookings/route-usage', 'POST', [
        'route_key' => 'assignment_redirect',
        'resolved_route_key' => 'workspace',
        'session_id' => '018f4df1-3b06-7d5c-9f02-3d8a749793aa',
        'booking_item_id' => 'must-not-be-logged',
    ]);
    $request->setUserResolver(fn () => $actor);

    Log::shouldReceive('info')->once()->with(
        'booking_management_route_viewed',
        Mockery::on(fn (array $context) => $context === [
            'route_key' => 'assignment_redirect',
            'resolved_route_key' => 'workspace',
            'session_id' => '018f4df1-3b06-7d5c-9f02-3d8a749793aa',
            'actor_id' => 'actor-1',
        ])
    );

    expect((new BookingRouteUsageController())->store($request)->getStatusCode())->toBe(202);
});

it('does not write evidence while pilot collection is disabled', function () {
    config()->set('booking_observability.route_usage.enabled', false);
    Log::shouldReceive('info')->never();
    $request = Request::create('/api/bookings/route-usage', 'POST', [
        'route_key' => 'workspace',
        'session_id' => '018f4df1-3b06-7d5c-9f02-3d8a749793aa',
    ]);

    expect((new BookingRouteUsageController())->store($request)->getStatusCode())->toBe(202);
});

it('fails closed when the approved collection window is absent or expired', function () {
    config()->set('booking_observability.route_usage', [
        'enabled' => true,
        'starts_at' => now()->subHours(2)->toIso8601String(),
        'ends_at' => now()->subHour()->toIso8601String(),
    ]);
    Log::shouldReceive('info')->never();
    $request = Request::create('/api/bookings/route-usage', 'POST', [
        'route_key' => 'operations',
        'session_id' => '018f4df1-3b06-7d5c-9f02-3d8a749793aa',
    ]);

    expect((new BookingRouteUsageController())->store($request)->getStatusCode())->toBe(202);
});
