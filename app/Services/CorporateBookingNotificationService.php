<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\User;
use App\Notifications\NewCorporateBookingNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CorporateBookingNotificationService
{
    public function notifyInternalTeam(Booking $booking): void
    {
        try {
            $recipients = User::permission('bookings.dispatch')
                ->where('is_active', true)
                ->get();

            if ($recipients->isEmpty()) {
                Log::warning('No active internal dispatch users available for corporate booking notification', [
                    'booking_id' => $booking->id,
                ]);
                return;
            }

            $booking->loadMissing('corporateAccount');
            Notification::send($recipients, new NewCorporateBookingNotification($booking));
        } catch (\Throwable $exception) {
            Log::warning('Corporate booking internal notification could not be queued', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
