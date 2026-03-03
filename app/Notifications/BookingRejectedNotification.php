<?php

namespace App\Notifications;

use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $booking;
    protected $reason;

    /**
     * Create a new notification instance.
     *
     * @param Booking $booking
     * @param string $reason
     */
    public function __construct(Booking $booking, string $reason)
    {
        $this->booking = $booking;
        $this->reason = $reason;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Booking Rejected - ' . $this->booking->booking_number)
            ->line('Your booking has been rejected.')
            ->line('Booking Number: ' . $this->booking->booking_number)
            ->line('Reason: ' . $this->reason)
            ->action('View Booking Details', url('/corporate/bookings/' . $this->booking->id));
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'title' => 'Booking Rejected',
            'message' => 'Your booking ' . $this->booking->booking_number . ' has been rejected.',
            'type' => 'booking_rejected',
            'data' => [
                'booking_id' => $this->booking->id,
                'booking_number' => $this->booking->booking_number,
                'reason' => $this->reason,
            ]
        ];
    }
}
