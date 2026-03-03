<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event for new driver assignment notifications.
 * Sent on the private channel: driver.{driverId}.assignments
 */
class AssignmentCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private string $channelName,
        public array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel($this->channelName),
        ];
    }

    public function broadcastAs(): string
    {
        return 'assignment.created';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
