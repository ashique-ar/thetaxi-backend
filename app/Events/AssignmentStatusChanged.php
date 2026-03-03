<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event for assignment status changes (accept, decline) from drivers.
 *
 * Sent on the private channel: admin.assignments
 * so the admin panel receives real-time updates when a driver
 * accepts or declines an assignment.
 *
 * @see Requirements 14.5, 14.6
 */
class AssignmentStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.assignments'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'assignment.status.changed';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
