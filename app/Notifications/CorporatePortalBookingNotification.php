<?php

namespace App\Notifications;

use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CorporatePortalBookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Booking $booking,
        private readonly string $state,
        private readonly ?string $reason = null,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (($this->booking->notification_email ?? true)
            && ! ($this->booking->skip_all_emails ?? false)
            && filled($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $message] = $this->copy();

        $mail = (new MailMessage)
            ->subject($subject.' - '.$this->reference())
            ->greeting('Hello '.($notifiable->first_name ?: 'there').',')
            ->line($message)
            ->line('Business: '.($this->booking->corporateAccount?->name ?? 'Corporate account'))
            ->line('Booking reference: '.$this->reference());

        if ($this->reason) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail->action('Open corporate booking', $this->portalUrl());
    }

    public function toArray(object $notifiable): array
    {
        [$title, $message] = $this->copy();

        return [
            'title' => $title,
            'message' => $message,
            'type' => 'corporate_booking_'.$this->state,
            'data' => [
                'booking_id' => (string) $this->booking->id,
                'booking_number' => $this->reference(),
                'reason' => $this->reason,
                'url' => $this->portalPath(),
            ],
        ];
    }

    private function copy(): array
    {
        return match ($this->state) {
            'approval_requested' => ['Approval requested', 'Your corporate booking is waiting for approval.'],
            'approved' => ['Booking approved', 'Your corporate booking request has been approved and is ready for scheduling.'],
            'rejected' => ['Booking not approved', 'Your corporate booking request was not approved.'],
            'submitted_for_employee' => ['Booking submitted', 'A corporate booking has been submitted for an employee you coordinate.'],
            default => ['Corporate booking received', 'Your corporate booking request has been received.'],
        };
    }

    private function reference(): string
    {
        return (string) ($this->booking->booking_number ?: $this->booking->id);
    }

    private function portalPath(): string
    {
        return '/corporate/bookings/'.$this->booking->id;
    }

    private function portalUrl(): string
    {
        return rtrim((string) config('app.portal_url', config('app.url')), '/').$this->portalPath();
    }
}
