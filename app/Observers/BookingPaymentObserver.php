<?php

namespace App\Observers;

use App\Models\Booking\Booking;
use App\Notifications\BookingLifecycleNotification;
use Illuminate\Support\Facades\Log;

class BookingPaymentObserver
{
    public function updated(Booking $booking): void
    {
        if (!$booking->wasChanged(['payment_status', 'payment_collection_status'])) {
            return;
        }

        $previousPayment = strtolower((string) $booking->getOriginal('payment_status'));
        $previousCollection = strtolower((string) $booking->getOriginal('payment_collection_status'));
        $payment = strtolower((string) $booking->payment_status);
        $collection = strtolower((string) $booking->payment_collection_status);

        $wasPaid = in_array($previousPayment, ['paid', 'refunded'], true)
            || in_array($previousCollection, ['paid', 'online_paid', 'driver_collected'], true);
        $isPaid = in_array($payment, ['paid', 'refunded'], true)
            || in_array($collection, ['paid', 'online_paid', 'driver_collected'], true);

        if (!$wasPaid && $isPaid) {
            $this->notify(
                $booking,
                'Payment received',
                'Payment for your booking has been received.',
                'booking_payment_paid'
            );
            return;
        }

        $pendingStatuses = ['payment_pending', 'pending_collection', 'billable'];
        if ($previousCollection !== ''
            && $previousCollection !== $collection
            && in_array($collection, $pendingStatuses, true)) {
            $this->notify(
                $booking,
                'Payment pending',
                'Payment is pending for your booking. Please follow the agreed payment method.',
                'booking_payment_pending'
            );
        }
    }

    private function notify(Booking $booking, string $title, string $message, string $eventType): void
    {
        try {
            $booking->loadMissing('customer.user');
            $booking->customer?->user?->notify(new BookingLifecycleNotification(
                $booking,
                $title,
                $message,
                $eventType,
            ));
        } catch (\Throwable $exception) {
            Log::warning('Booking payment notification could not be queued', [
                'booking_id' => $booking->id,
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
