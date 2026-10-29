<?php

namespace App\Notifications;

use App\Models\Booking\Booking;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class WebsiteBookingNeedsOwnerNotification extends Notification implements ShouldBroadcast, ShouldQueue
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
            'title' => 'Booking needs an employee',
            'description' => "Booking {$number} is waiting for an employee to take ownership.",
            'type' => 'booking_needs_owner',
            'link' => '/bookings/list?queue=needs_responsible_owner',
            'useRouter' => true,
            'data' => [
                'booking_id' => (string) $this->booking->id,
                'booking_number' => $number,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'title' => 'Booking needs an employee',
            'description' => 'A booking is waiting in the unassigned booking queue.',
            'type' => 'booking_needs_owner',
            'link' => '/bookings/list?queue=needs_responsible_owner',
            'useRouter' => true,
        ]);
    }
}
