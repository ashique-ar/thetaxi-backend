<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event for admin-side assignment changes sent to the driver's mobile channel.
 *
 * Sent on the private channel: driver.{driverId}.assignments
 * so the driver's mobile app receives real-time updates when an admin
 * creates, modifies, or cancels an assignment.
 *
 * @see Requirements 14.5, 14.6
 */
class AdminAssignmentUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private string $driverId,
        public array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("driver.{$this->driverId}.assignments"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'assignment.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
