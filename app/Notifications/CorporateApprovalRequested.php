<?php

namespace App\Notifications;

use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class CorporateApprovalRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Booking $booking, private readonly int $pendingCount)
    {
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
            'title' => 'Booking approval required',
            'message' => "Booking #{$reference} is waiting for your company approval.",
            'type' => 'corporate_booking_approval_requested',
            'url' => '/corporate/approvals/'.$this->booking->id,
            'pending_count' => $this->pendingCount,
            'data' => [
                'booking_id' => (string) $this->booking->id,
                'booking_number' => $reference,
                'corporate_id' => (string) $this->booking->corporate_account_id,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
