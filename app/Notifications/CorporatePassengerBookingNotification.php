<?php

namespace App\Notifications;

use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CorporatePassengerBookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Booking $booking,
        private readonly string $state = 'received',
        private readonly ?string $reason = null,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reference = (string) ($this->booking->booking_number ?: $this->booking->id);
        [$subject, $message] = match ($this->state) {
            'approval_requested' => ['Corporate booking received', 'Your booking is waiting for approval.'],
            'approved' => ['Corporate booking approved', 'Your booking has been approved and is being arranged.'],
            'rejected' => ['Corporate booking not approved', 'Your booking request was not approved.'],
            default => ['Corporate booking received', 'Your booking request has been received.'],
        };

        $mail = (new MailMessage)
            ->subject($subject.' - '.$reference)
            ->greeting('Your travel booking update')
            ->line($message)
            ->line('Booking reference: '.$reference)
            ->line('This booking was arranged through '.($this->booking->corporateAccount?->name ?? 'your organization').'.');
        if ($this->reason) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail;
    }
}
