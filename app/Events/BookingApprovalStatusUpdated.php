<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingApprovalStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(private readonly string $userId, private readonly string $bookingId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'booking.approval.updated';
    }

    public function broadcastWith(): array
    {
        return ['booking_id' => $this->bookingId];
    }
}
