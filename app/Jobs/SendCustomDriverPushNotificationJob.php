<?php

namespace App\Jobs;

use App\Models\Driver\Driver;
use App\Services\Driver\NotificationTriggerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCustomDriverPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $driverId,
        public string $title,
        public string $body,
        public ?string $triggeredBy = null,
    ) {}

    public function handle(NotificationTriggerService $notifications): void
    {
        $driver = Driver::query()->where('is_active', true)->find($this->driverId);
        if (!$driver) {
            return;
        }

        $notifications->sendDriverPushNotification(
            $driver,
            'driver_custom_push',
            $this->title,
            $this->body,
            ['triggered_by' => (string) $this->triggeredBy]
        );
    }
}
