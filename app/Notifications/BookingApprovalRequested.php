<?php

namespace App\Notifications;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingApproval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class BookingApprovalRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Booking $booking,
        private readonly BookingApproval $approval,
        private readonly int $pendingCount,
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
            'title' => "Booking #{$reference} awaiting approval",
            'message' => 'Priority: '.($this->approval->priority ?? 'normal'),
            'type' => 'booking_approval_requested',
            'corporate_name' => $this->booking->corporateAccount?->name,
            'url' => '/bookings/list?operations_queue=needs_approval&status=pending_approval&requires_approval=true',
            'pending_count' => $this->pendingCount,
            'data' => [
                'booking_id' => (string) $this->booking->id,
                'booking_number' => $reference,
                'approval_id' => (string) $this->approval->id,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
