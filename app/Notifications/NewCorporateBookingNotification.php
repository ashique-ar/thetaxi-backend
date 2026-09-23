<?php

namespace App\Notifications;

use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewCorporateBookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Booking $booking)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return empty($notifiable->email) ? ['database'] : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New corporate booking - '.$this->reference())
            ->line('A new corporate booking has been received and is ready for the internal team to review.')
            ->line('Company: '.($this->booking->corporateAccount?->name ?? 'Corporate account'))
            ->line('Booking: '.$this->reference())
            ->action('Review booking', $this->internalUrl());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New corporate booking',
            'message' => ($this->booking->corporateAccount?->name ?? 'A corporate customer')
                .' submitted booking '.$this->reference().'.',
            'type' => 'corporate_booking_received',
            'url' => $this->internalPath(),
            'data' => [
                'booking_id' => (string) $this->booking->id,
                'booking_number' => $this->reference(),
                'corporate_id' => (string) $this->booking->corporate_account_id,
                'url' => $this->internalPath(),
            ],
        ];
    }

    private function reference(): string
    {
        return (string) ($this->booking->booking_number ?: $this->booking->id);
    }

    private function internalPath(): string
    {
        return '/bookings/list';
    }

    private function internalUrl(): string
    {
        return rtrim((string) config('app.portal_url', config('app.url')), '/').$this->internalPath();
    }
}
