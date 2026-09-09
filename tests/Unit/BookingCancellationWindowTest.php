<?php

use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function cancellableBookingAt(string $start): Booking
{
    $startAt = Carbon::parse($start);
    $booking = Mockery::mock(Booking::class)->makePartial();
    $booking->status = 'confirmed';
    $item = new BookingItem();
    $item->from_date = $startAt->copy()->startOfDay();
    $item->from_time = $startAt->format('H:i:s');
    $booking->shouldReceive('primaryItem')->andReturn($item);

    return $booking;
}

it('allows cancellation up to and including two hours before booking start', function () {
    Carbon::setTestNow('2026-09-09 10:00:00');

    expect(cancellableBookingAt('2026-09-09 12:00:00')->canBeCancelled())->toBeTrue()
        ->and(cancellableBookingAt('2026-09-09 12:01:00')->canBeCancelled())->toBeTrue();
});

it('blocks cancellation inside the two hour window', function () {
    Carbon::setTestNow('2026-09-09 10:00:00');
    $booking = cancellableBookingAt('2026-09-09 11:59:59');

    expect($booking->canBeCancelled())->toBeFalse()
        ->and($booking->cancellationBlockReason())
        ->toBe('Bookings can only be cancelled at least 2 hours before the scheduled start.');
});

it('blocks cancellation after a booking starts or reaches a final state', function (string $status) {
    Carbon::setTestNow('2026-09-09 10:00:00');
    $booking = cancellableBookingAt('2026-09-10 10:00:00');
    $booking->status = $status;

    expect($booking->canBeCancelled())->toBeFalse();
})->with(['in_progress', 'completed', 'cancelled']);
