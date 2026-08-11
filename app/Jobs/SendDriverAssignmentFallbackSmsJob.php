<?php

namespace App\Jobs;

use App\Models\Driver\DriverAssignmentNotification;
use App\Services\Driver\NotificationTriggerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class SendDriverAssignmentFallbackSmsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $uniqueFor = 3600;

    public function __construct(public string $notificationId)
    {
        $this->onQueue(config('services.firebase.queue', 'driver-notifications'));
    }

    public function uniqueId(): string { return $this->notificationId; }

    public function handle(NotificationTriggerService $service): void
    {
        $notification = DriverAssignmentNotification::query()->find($this->notificationId);
        if ($notification) {
            $service->processFallback($notification);
        }
    }
}
