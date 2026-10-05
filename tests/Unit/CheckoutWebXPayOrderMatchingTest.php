<?php

use App\Http\Controllers\CheckoutController;
use App\Models\Booking\Booking;

function webxpayOrderMatchesBooking(Booking $booking, array $verification): bool
{
    $controller = (new ReflectionClass(CheckoutController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CheckoutController::class, 'matchesBookingGatewayOrder');

    return $method->invoke($controller, $booking, $verification);
}

it('accepts a signed order for this booking when session/order snapshot is missing or from an older attempt', function () {
    $booking = new Booking();
    $booking->booking_number = 'BK002546';
    $booking->payment_gateway_order_id = 'BK002546-1790742818';

    expect(webxpayOrderMatchesBooking($booking, [
        'order_id' => 'BK002546-1790740854',
        'booking_number' => 'BK002546',
    ]))->toBeTrue();
});

it('rejects an order that belongs to another booking or has an invalid order format', function () {
    $booking = new Booking();
    $booking->booking_number = 'BK002546';

    expect(webxpayOrderMatchesBooking($booking, [
        'order_id' => 'BK002547-1790740854',
        'booking_number' => 'BK002546',
    ]))->toBeFalse()
        ->and(webxpayOrderMatchesBooking($booking, [
            'order_id' => 'BK002546-invalid',
            'booking_number' => 'BK002546',
        ]))->toBeFalse()
        ->and(webxpayOrderMatchesBooking($booking, [
            'order_id' => 'BK002546-1790740854',
            'booking_number' => 'BK002547',
        ]))->toBeFalse();
});
