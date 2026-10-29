<?php

namespace App\Notifications;

use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class BookingResponsibilityAssignedNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(private readonly Booking $booking)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $number = (string) ($this->booking->booking_number ?: $this->booking->id);

        return [
            'title' => 'New booking assigned to you',
            'description' => "Booking {$number} needs your attention.",
            'type' => 'booking_responsibility_assigned',
            'link' => '/bookings/list?search='.rawurlencode($number),
            'useRouter' => true,
            'data' => ['booking_id' => (string) $this->booking->id, 'booking_number' => $number],
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $number = (string) ($this->booking->booking_number ?: $this->booking->id);

        return new BroadcastMessage([
            'title' => 'New booking assigned to you',
            'description' => "Booking {$number} needs your attention.",
            'type' => 'booking_responsibility_assigned',
            'link' => '/bookings/list?search='.rawurlencode($number),
            'useRouter' => true,
        ]);
    }
}
