<?php

namespace App\Observers;

use App\Models\Booking\Booking;
use App\Services\Sales\BookingAttributionService;

class BookingSalesObserver
{
    public function created(Booking $booking): void
    {
        if ($booking->confirmed || $booking->status === 'confirmed' || $booking->confirmed_at) {
            app(BookingAttributionService::class)->captureConfirmation($booking);
        }
    }

    public function updated(Booking $booking): void
    {
        if ($booking->wasChanged(['confirmed', 'status', 'confirmed_at'])) {
            app(BookingAttributionService::class)->captureConfirmation($booking);
        }
    }
}
