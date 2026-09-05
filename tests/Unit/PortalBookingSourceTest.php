<?php

use App\Models\Booking\Booking;
use App\Services\BookingFlowService;

function applyPortalBookingSource(Booking $booking): void
{
    $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(BookingFlowService::class, 'applyPortalBookingSource');
    $method->invoke($service, $booking);
}

function sourceTestBooking(array $attributes): Booking
{
    $booking = (new ReflectionClass(Booking::class))->newInstanceWithoutConstructor();
    $booking->setRawAttributes($attributes);

    return $booking;
}

it('marks a new non-corporate portal booking as internal', function () {
    $booking = sourceTestBooking([
        'is_corporate_booking' => false,
        'created_by_user_id' => 'existing-user',
        'created_user_id' => 'existing-user',
    ]);

    applyPortalBookingSource($booking);

    expect($booking->booking_source)->toBe('internal')
        ->and($booking->created_from)->toBe('internal');
});

it('retains the source when portal staff edit a website booking', function () {
    $booking = sourceTestBooking([
        'is_corporate_booking' => false,
        'booking_source' => 'public',
        'created_from' => 'web',
    ]);

    applyPortalBookingSource($booking);

    expect($booking->booking_source)->toBe('public')
        ->and($booking->created_from)->toBe('web');
});

it('marks a corporate portal booking as corporate with an internal creation channel', function () {
    $booking = sourceTestBooking([
        'is_corporate_booking' => true,
        'created_by_user_id' => 'existing-user',
        'created_user_id' => 'existing-user',
    ]);

    applyPortalBookingSource($booking);

    expect($booking->booking_source)->toBe('corporate')
        ->and($booking->created_from)->toBe('internal');
});
