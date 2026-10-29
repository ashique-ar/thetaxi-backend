<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\BusinessSetting;
use App\Models\User;
use App\Notifications\NewCorporateBookingNotification;
use App\Notifications\CorporatePortalBookingNotification;
use App\Notifications\CorporateAccountBookingNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CorporateBookingNotificationService
{
    public function notifyPortalUser(?User $user, Booking $booking, string $state, ?string $reason = null): void
    {
        if (! $user) {
            return;
        }

        try {
            $user->notify(new CorporatePortalBookingNotification($booking, $state, $reason));
        } catch (\Throwable $exception) {
            Log::warning('Corporate portal booking notification could not be queued', [
                'booking_id' => $booking->id,
                'recipient_user_id' => $user->id,
                'state' => $state,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function notifyPortalParticipants(Booking $booking, string $state, ?string $reason = null): void
    {
        User::query()->whereIn('id', array_values(array_unique(array_filter([
            $booking->created_by_user_id,
            $booking->employee_id,
        ]))))->get()
            ->each(fn (User $user) => $this->notifyPortalUser($user, $booking, $state, $reason));
    }

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

            $businessEmail = trim((string) BusinessSetting::getSetting('corporate_booking_notification_email'));
            if (filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) {
                Notification::route('mail', strtolower($businessEmail))->notify($notification);
            }

            $corporateRecipients = collect($booking->corporateAccount?->booking_notification_emails ?? [])
                ->filter(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
                ->map(fn ($email) => strtolower(trim($email)))
                ->reject(fn ($email) => $email === strtolower($businessEmail))
                ->unique()
                ->values();

            if ($corporateRecipients->isEmpty()) {
                Log::info('No distinct corporate account booking email recipients; internal in-app/email notifications were still sent', [
                    'booking_id' => $booking->id,
                    'corporate_id' => $booking->corporate_account_id,
                ]);
            }

            foreach ($corporateRecipients as $email) {
                Notification::route('mail', $email)->notify(new CorporateAccountBookingNotification($booking));
            }
        } catch (\Throwable $exception) {
            Log::warning('Corporate booking internal notification could not be queued', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
