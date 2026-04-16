<?php

namespace App\Jobs;

use App\Models\DriverAssignment;
use App\Services\Driver\NotificationTriggerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued job for assignment notification delivery.
 *
 * Attempts WebSocket and Firebase push delivery, then schedules
 * controlled retries through NotificationTriggerService when needed.
 */
class SendAssignmentNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of delivery attempts before giving up.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Delay in seconds between retry attempts.
     */
    public const RETRY_DELAY_SECONDS = 30;

    /**
     * Disable queue worker auto-retries; we retry explicitly in service.
     */
    public int $tries = 1;

    public function __construct(
        public DriverAssignment $assignment,
        public int $attempt = 1,
        public array $context = []
    ) {}

    public function handle(NotificationTriggerService $service): void
    {
        $service->processAssignmentNotificationAttempt($this->assignment, $this->attempt, $this->context);
    }
}
