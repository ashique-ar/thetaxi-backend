<?php

use App\Http\Controllers\CheckoutController;
use App\Models\Booking\Booking;

uses(Tests\TestCase::class);

function webxpayOrderMatchesBooking(Booking $booking, array $verification): bool
{
    $controller = (new ReflectionClass(CheckoutController::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(CheckoutController::class, 'matchesBookingGatewayOrder');
    $method->setAccessible(true);

    return $method->invoke($controller, $booking, $verification);
}

it('accepts a verified legacy order when its booking number is bound into the order id', function () {
    // The defect: older bookings can lack the dedicated order column even though WebXPay signed an order ID
    // that embeds the exact booking number; the current exact-column-only check rejects that paid callback.
    $booking = new Booking();
    $booking->booking_number = 'BK002542';

    expect(webxpayOrderMatchesBooking($booking, [
        'order_id' => 'BK002542-1790740854',
        'booking_number' => 'BK002542',
    ]))->toBeTrue();
});

it('accepts older attempts for the same booking and rejects malformed or cross-booking orders', function () {
    $booking = new Booking();
    $booking->booking_number = 'BK002542';
    $booking->payment_gateway_order_id = 'BK002542-1790740854';

    expect(webxpayOrderMatchesBooking($booking, [
        'order_id' => 'BK002542-1790740854',
        'booking_number' => 'BK002542',
    ]))->toBeTrue()
        ->and(webxpayOrderMatchesBooking($booking, [
            'order_id' => 'BK002542-1790740855',
            'booking_number' => 'BK002542',
        ]))->toBeTrue()
        ->and(webxpayOrderMatchesBooking(tap(new Booking(), function ($booking) { $booking->booking_number = 'BK002542'; }), [
            'order_id' => 'BK002543-1790740854',
            'booking_number' => 'BK002542',
        ]))->toBeFalse()
        ->and(webxpayOrderMatchesBooking(tap(new Booking(), function ($booking) { $booking->booking_number = 'BK002542'; }), [
            'order_id' => 'BK002542-invalid',
            'booking_number' => 'BK002543',
        ]))->toBeFalse();
});
