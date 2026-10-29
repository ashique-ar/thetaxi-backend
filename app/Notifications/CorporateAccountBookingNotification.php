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

    public function __construct(
        private readonly Booking $booking,
        private readonly string $state = 'received',
        private readonly ?string $reason = null,
    )
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
        [$subject, $message, $status] = match ($this->state) {
            'approval_requested' => ['Approval requested', 'The booking is waiting for approval.', 'Waiting for approval'],
            'approved' => ['Booking approved', 'The booking has been approved and is ready for scheduling.', 'Approved'],
            'rejected' => ['Booking not approved', 'The booking request was not approved.', 'Not approved'],
            default => ['Booking received', 'A transport booking has been submitted.', $this->booking->status === 'pending_approval' ? 'Waiting for approval' : 'Received'],
        };

        $mail = (new MailMessage)
            ->subject($subject.' - '.$reference)
            ->greeting('Corporate booking update')
            ->line($message.' Business: '.($this->booking->corporateAccount?->name ?? 'your business').'.')
            ->line('Booking reference: '.$reference)
            ->line('Current status: '.$status);

        if ($this->reason) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail->action('Open corporate booking', rtrim((string) config('app.portal_url', config('app.url')), '/').'/corporate/bookings/'.$this->booking->id);
    }
}
