<?php

namespace App\Notifications;

use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class BookingApprovalCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Booking $booking,
        private readonly string $approvedByName,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $reference = (string) ($this->booking->booking_number ?: $this->booking->id);

        return [
            'title' => "Booking #{$reference} approved",
            'message' => "Approved by {$this->approvedByName}.",
            'type' => 'booking_approval_completed',
            'url' => '/bookings/list',
            'data' => [
                'booking_id' => (string) $this->booking->id,
                'booking_number' => $reference,
                'approved_by' => $this->approvedByName,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
