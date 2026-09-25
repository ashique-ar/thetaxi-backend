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
            $booking->loadMissing('corporateAccount');
            $notification = new NewCorporateBookingNotification($booking);

            // Keep the in-app notification available to internal dispatch staff.
            $dispatchUsers = User::permission('bookings.dispatch')
                ->where('is_active', true)
                ->get();
            Notification::send($dispatchUsers, $notification);

            $recipients = collect($booking->corporateAccount?->booking_notification_emails ?? [])
                ->filter(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
                ->map(fn ($email) => strtolower(trim($email)))
                ->unique()
                ->values();

            if ($recipients->isEmpty()) {
                Log::info('No configured corporate booking notification email recipients; in-app notifications were still sent', [
                    'booking_id' => $booking->id,
                    'corporate_id' => $booking->corporate_account_id,
                ]);
                return;
            }

            foreach ($recipients as $email) {
                Notification::route('mail', $email)->notify($notification);
            }
        } catch (\Throwable $exception) {
            Log::warning('Corporate booking internal notification could not be queued', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
