<?php

namespace App\Jobs;

use App\Models\Booking\Booking;
use App\Models\Driver\Driver;
use App\Services\Customer\CustomerNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued job that pushes an FCM "driver assigned" notification to the
 * rider's mobile app.
 */
class SendCustomerDriverAssignedNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public Booking $booking,
        public Driver $driver,
    ) {}

    public function handle(CustomerNotificationService $service): void
    {
        $service->notifyDriverAssigned($this->booking, $this->driver);
    }
}
