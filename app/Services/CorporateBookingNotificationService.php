<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\BusinessSetting;
use App\Models\User;
use App\Notifications\NewCorporateBookingNotification;
use App\Notifications\CorporatePortalBookingNotification;
use App\Notifications\CorporateAccountBookingNotification;
use App\Notifications\CorporatePassengerBookingNotification;
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

        $this->notifyCorporateAccount($booking, $state, $reason);
        $this->notifyPassenger($booking, $state, $reason);
    }

    public function notifyInternalTeam(Booking $booking): void
    {
        try {
            $booking->loadMissing('corporateAccount');
            $notification = new NewCorporateBookingNotification($booking);

            $this->notifyPassenger(
                $booking,
                $booking->status === 'pending_approval' ? 'approval_requested' : 'received',
            );

            // Keep the in-app notification available to internal dispatch staff.
            $dispatchUsers = User::permission('bookings.dispatch')
                ->where('is_active', true)
                ->get();
            Notification::send($dispatchUsers, $notification);

            $businessEmail = trim((string) BusinessSetting::getSetting('corporate_booking_notification_email'));
            if (filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) {
                Notification::route('mail', strtolower($businessEmail))->notify($notification);
            }

            $this->notifyCorporateAccount($booking, $booking->status === 'pending_approval' ? 'approval_requested' : 'received');
        } catch (\Throwable $exception) {
            Log::warning('Corporate booking internal notification could not be queued', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function notifyCorporateAccount(Booking $booking, string $state, ?string $reason = null): void
    {
        try {
            $booking->loadMissing('corporateAccount');
            $businessEmail = strtolower(trim((string) BusinessSetting::getSetting('corporate_booking_notification_email')));
            $recipients = collect($booking->corporateAccount?->booking_notification_emails ?? [])
                ->filter(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
                ->map(fn ($email) => strtolower(trim($email)))
                ->reject(fn ($email) => $email === $businessEmail)
                ->unique()
                ->values();

            foreach ($recipients as $email) {
                Notification::route('mail', $email)->notify(new CorporateAccountBookingNotification($booking, $state, $reason));
            }
        } catch (\Throwable $exception) {
            Log::warning('Corporate account booking email could not be queued', [
                'booking_id' => $booking->id,
                'corporate_id' => $booking->corporate_account_id,
                'state' => $state,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function notifyPassenger(Booking $booking, string $state, ?string $reason = null): void
    {
        if (! ($booking->notification_email ?? true) || ($booking->skip_all_emails ?? false)) {
            return;
        }

        try {
            $email = strtolower(trim((string) (
                $booking->customer?->email
                ?: data_get($booking->workflow_data, 'corporate_contact.email')
            )));
            $portalEmails = User::query()->whereIn('id', array_values(array_unique(array_filter([
                $booking->created_by_user_id,
                $booking->employee_id,
            ]))))->pluck('email')->map(fn ($portalEmail) => strtolower(trim((string) $portalEmail)));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $portalEmails->contains($email)) {
                return;
            }

            Notification::route('mail', $email)->notify(new CorporatePassengerBookingNotification($booking, $state, $reason));
        } catch (\Throwable $exception) {
            Log::warning('Corporate passenger booking email could not be queued', [
                'booking_id' => $booking->id,
                'state' => $state,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
