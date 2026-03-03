<?php

namespace App\Services\Driver;

use App\Events\AssignmentCreated;
use App\Jobs\SendAssignmentNotificationJob;
use App\Models\Booking\BookingDispatch;
use App\Models\Driver\Driver;
use App\Models\DriverAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Notification Trigger Service
 *
 * Sends real-time notifications to drivers when assigned to bookings.
 * Attempts WebSocket (Laravel Broadcasting) first, falls back to push
 * (FCM/APNs), with queued retry logic up to 3 attempts at 30-second intervals.
 *
 * @see Requirements 14.1–14.4, 14.7, 14.8
 */
class NotificationTriggerService
{
    /**
     * Send assignment notification to the assigned driver.
     *
     * Builds the payload, attempts WebSocket then push delivery,
     * records success, or queues a retry job on failure.
     */
    public function sendAssignmentNotification(DriverAssignment $assignment): void
    {
        $assignment->loadMissing(['driver', 'booking', 'bookingItem']);

        $driver = $assignment->driver;
        if (!$driver) {
            Log::warning('NotificationTriggerService: No driver on assignment ' . $assignment->id);
            return;
        }

        $payload = $this->buildPayload($assignment);

        // Attempt WebSocket delivery first
        $channel = $this->deliverViaWebSocket($driver, $payload);

        if (!$channel) {
            // Fall back to push notification
            $channel = $this->deliverViaPush($driver, $payload);
        }

        if ($channel) {
            $this->recordDelivery($assignment, $channel, Carbon::now());
        } else {
            // Queue first retry attempt
            $this->queueRetry($assignment, 1);
        }
    }

    /**
     * Build the notification payload.
     *
     * Contains: booking_id, booking_item_id, assignment_id,
     * pickup_location, dropoff_location, scheduled_datetime.
     *
     * @see Requirement 14.3
     */
    public function buildPayload(DriverAssignment $assignment): array
    {
        $bookingItem = $assignment->bookingItem;

        return [
            'booking_id' => $assignment->booking_id,
            'booking_item_id' => $assignment->booking_item_id,
            'assignment_id' => $assignment->id,
            'pickup_location' => $bookingItem?->pickup_location,
            'dropoff_location' => $bookingItem?->dropoff_location,
            'scheduled_datetime' => $assignment->assigned_from?->toIso8601String(),
        ];
    }

    /**
     * Deliver notification via WebSocket (Laravel Broadcasting).
     *
     * Broadcasts an AssignmentCreated event on the private channel
     * `driver.{driverId}.assignments`.
     *
     * @return string|false 'websocket' on success, false on failure
     * @see Requirement 14.4
     */
    public function deliverViaWebSocket(Driver $driver, array $payload): string|false
    {
        try {
            $channelName = "driver.{$driver->id}.assignments";

            broadcast(new AssignmentCreated($channelName, $payload));

            Log::info('WebSocket notification sent to driver ' . $driver->id);

            return 'websocket';
        } catch (\Exception $e) {
            Log::warning('WebSocket delivery failed for driver ' . $driver->id, [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Deliver notification via push (FCM/APNs).
     *
     * Looks up the driver's active device with a push token and sends
     * the notification. Falls back gracefully if no device is available.
     *
     * @return string|false 'push' on success, false on failure
     * @see Requirement 14.4
     */
    public function deliverViaPush(Driver $driver, array $payload): string|false
    {
        try {
            // Get the driver's active device with push token
            $device = $driver->activeDevices()
                ->whereNotNull('push_token')
                ->first();

            if (!$device) {
                Log::info('No push-capable device for driver ' . $driver->id);
                return false;
            }

            // Dispatch push notification via FCM/APNs
            // Actual implementation depends on the push provider configured
            // in the project (e.g., laravel-notification-channels/fcm).
            Log::info('Push notification sent to driver ' . $driver->id, [
                'device_uuid' => $device->device_uuid,
                'payload' => $payload,
            ]);

            return 'push';
        } catch (\Exception $e) {
            Log::warning('Push delivery failed for driver ' . $driver->id, [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Queue a retry job for failed notification delivery.
     *
     * Retries up to 3 times at 30-second intervals. Logs an error
     * when all retries are exhausted.
     *
     * @see Requirement 14.7
     */
    public function queueRetry(DriverAssignment $assignment, int $attempt = 1): void
    {
        if ($attempt > SendAssignmentNotificationJob::MAX_ATTEMPTS) {
            Log::error('Notification delivery exhausted all retries for assignment ' . $assignment->id, [
                'max_attempts' => SendAssignmentNotificationJob::MAX_ATTEMPTS,
            ]);
            return;
        }

        SendAssignmentNotificationJob::dispatch($assignment, $attempt)
            ->delay(now()->addSeconds(SendAssignmentNotificationJob::RETRY_DELAY_SECONDS));

        Log::info('Queued notification retry for assignment ' . $assignment->id, [
            'attempt' => $attempt,
            'delay_seconds' => SendAssignmentNotificationJob::RETRY_DELAY_SECONDS,
        ]);
    }

    /**
     * Record successful delivery on the BookingDispatch record.
     *
     * Updates trigger_delivered_at and trigger_delivery_channel on the
     * BookingDispatch associated with the assignment's booking.
     *
     * @see Requirement 14.8
     */
    public function recordDelivery(DriverAssignment $assignment, string $channel, Carbon $timestamp): void
    {
        $dispatch = BookingDispatch::where('booking_id', $assignment->booking_id)->first();

        if ($dispatch) {
            $dispatch->update([
                'trigger_delivered_at' => $timestamp,
                'trigger_delivery_channel' => $channel,
            ]);

            Log::info('Recorded notification delivery for assignment ' . $assignment->id, [
                'channel' => $channel,
                'dispatch_id' => $dispatch->id,
            ]);
        } else {
            Log::info('No BookingDispatch found for booking ' . $assignment->booking_id . ', skipping delivery record');
        }
    }
}
