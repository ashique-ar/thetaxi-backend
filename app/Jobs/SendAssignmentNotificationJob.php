<?php

namespace App\Jobs;

use App\Models\DriverAssignment;
use App\Services\Driver\NotificationTriggerService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job for retrying assignment notification delivery.
 *
 * Attempts WebSocket first, falls back to FCM/APNs push notification.
 * Retries up to 3 total attempts at 30-second intervals on failure.
 * Records delivery timestamp and channel on BookingDispatch upon success.
 *
 * @see Requirements 14.2, 14.4, 14.7, 14.8
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

    public function __construct(
        public DriverAssignment $assignment,
        public int $attempt = 1
    ) {}

    public function handle(NotificationTriggerService $service): void
    {
        $this->assignment->loadMissing(['driver', 'booking', 'bookingItem']);

        $driver = $this->assignment->driver;
        if (!$driver) {
            Log::warning('SendAssignmentNotificationJob: No driver found for assignment ' . $this->assignment->id);
            return;
        }

        $payload = [
            'booking_id' => $this->assignment->booking_id,
            'booking_item_id' => $this->assignment->booking_item_id,
            'assignment_id' => $this->assignment->id,
            'pickup_location' => $this->assignment->bookingItem?->pickup_location,
            'dropoff_location' => $this->assignment->bookingItem?->dropoff_location,
            'scheduled_datetime' => $this->assignment->assigned_from?->toIso8601String(),
        ];

        // Attempt WebSocket delivery first
        $channel = $service->deliverViaWebSocket($driver, $payload);

        // Fall back to push notification if WebSocket fails
        if (!$channel) {
            $channel = $service->deliverViaPush($driver, $payload);
        }

        if ($channel) {
            // Delivery succeeded — record on BookingDispatch
            $service->recordDelivery($this->assignment, $channel, Carbon::now());

            Log::info('SendAssignmentNotificationJob: Delivered via ' . $channel, [
                'assignment_id' => $this->assignment->id,
                'attempt' => $this->attempt,
            ]);
        } else {
            // Both channels failed — queue retry if under max attempts
            Log::warning('SendAssignmentNotificationJob: Delivery failed', [
                'assignment_id' => $this->assignment->id,
                'attempt' => $this->attempt,
            ]);

            $service->queueRetry($this->assignment, $this->attempt + 1);
        }
    }
}
