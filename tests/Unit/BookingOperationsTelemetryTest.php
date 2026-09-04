<?php

use App\Http\Middleware\BookingOperationsTelemetry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;


it('publishes server timing and alerts when a booking request misses its target', function () {
    config()->set('booking_observability.performance.read_target_ms', -1);
    Log::shouldReceive('warning')
        ->once()
        ->with('booking_operations_api_slow', Mockery::on(fn (array $context) =>
            $context['method'] === 'GET'
            && $context['status'] === 200
            && $context['target_ms'] === -1
            && array_key_exists('duration_ms', $context)
        ));

    $response = (new BookingOperationsTelemetry())->handle(
        Request::create('/api/bookings/example/tracking-summary', 'GET', [
            'booking_item_id' => 'item-1',
        ]),
        fn () => new Response('ok')
    );

    expect($response->headers->get('Server-Timing'))->toMatch('/^booking;dur=\d+\.\d$/');
});

it('logs failed booking requests and rethrows without recording sensitive payloads', function () {
    Log::shouldReceive('error')
        ->once()
        ->with('booking_operations_api_failed', Mockery::on(fn (array $context) =>
            $context['status'] === 500
            && $context['exception_class'] === RuntimeException::class
            && !array_key_exists('payload', $context)
        ));

    expect(fn () => (new BookingOperationsTelemetry())->handle(
        Request::create('/api/booking-lifecycle/complete-booking', 'POST', [
            'booking_id' => 'booking-1',
            'booking_item_id' => 'item-1',
            'notes' => 'must not be logged',
        ]),
        fn () => throw new RuntimeException('failure detail must not be logged')
    ))->toThrow(RuntimeException::class);
});
