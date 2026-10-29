<?php

namespace App\Notifications;

use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CorporateAccountBookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Booking $booking)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reference = (string) ($this->booking->booking_number ?: $this->booking->id);
        $status = match ($this->booking->status) {
            'pending_approval' => 'Waiting for approval',
            'approved' => 'Approved and awaiting operations',
            default => 'Received',
        };

        return (new MailMessage)
            ->subject('Corporate booking update - '.$reference)
            ->greeting('Corporate booking update')
            ->line('A transport booking has been submitted for '.($this->booking->corporateAccount?->name ?? 'your business').'.')
            ->line('Booking reference: '.$reference)
            ->line('Current status: '.$status)
            ->action('Open corporate booking', rtrim((string) config('app.portal_url', config('app.url')), '/').'/corporate/bookings/'.$this->booking->id);
    }
}
