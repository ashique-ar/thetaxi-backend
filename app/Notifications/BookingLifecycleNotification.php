<?php

namespace App\Notifications;

use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Booking $booking,
        private readonly string $title,
        private readonly string $message,
        private readonly string $eventType,
        private readonly bool $emailEnabled = true,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($this->emailEnabled && (bool) ($this->booking->notification_email ?? true) && !empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title . ' - ' . $this->booking->booking_number)
            ->line($this->message)
            ->line('Booking reference: ' . $this->booking->booking_number)
            ->action('Check booking status', route('booking.status'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->eventType,
            'data' => [
                'booking_id' => $this->booking->id,
                'booking_number' => $this->booking->booking_number,
                'status_url' => route('booking.status'),
            ],
        ];
    }
}
