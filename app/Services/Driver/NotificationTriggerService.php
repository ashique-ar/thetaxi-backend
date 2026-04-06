<?php

namespace App\Services\Driver;

use App\Events\AssignmentCreated;
use App\Jobs\SendAssignmentNotificationJob;
use App\Models\Booking\BookingDispatch;
use App\Models\Driver\Driver;
use App\Models\DriverAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
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
        SendAssignmentNotificationJob::dispatch($assignment, 1)
            ->onQueue(config('services.firebase.queue', 'driver-notifications'));

        Log::info('Queued assignment notification', [
            'assignment_id' => $assignment->id,
            'queue' => config('services.firebase.queue', 'driver-notifications'),
        ]);
    }

    /**
     * Process one queued notification delivery attempt.
     */
    public function processAssignmentNotificationAttempt(DriverAssignment $assignment, int $attempt = 1): void
    {
        $assignment->loadMissing(['driver', 'booking', 'bookingItem']);

        $driver = $assignment->driver;
        if (!$driver) {
            Log::warning('NotificationTriggerService: No driver on assignment ' . $assignment->id);
            return;
        }

        $payload = $this->buildPayload($assignment);
        $channels = [];

        $websocketChannel = $this->deliverViaWebSocket($driver, $payload);
        if ($websocketChannel) {
            $channels[] = $websocketChannel;
        }

        // Always attempt push as well so mobile app gets a system notification.
        $pushChannel = $this->deliverViaPush($driver, $payload);
        if ($pushChannel) {
            $channels[] = $pushChannel;
        }

        if (!empty($channels)) {
            $this->recordDelivery($assignment, implode('+', array_unique($channels)), Carbon::now());
            return;
        }

        $this->queueRetry($assignment, $attempt + 1);
    }

    /**
     * Send a manual test notification to a driver's mobile app/devices.
     *
     * Used by admin portal "test notification" action in driver detail page.
     */
    public function sendDriverTestNotification(Driver $driver, array $context = []): array
    {
        $title = trim((string) ($context['title'] ?? 'Driver Notification Test'));
        $body = trim((string) ($context['body'] ?? 'This is a test notification from TheTaxi admin portal.'));

        $payload = [
            'event_type' => 'driver_test_notification',
            'driver_id' => $driver->id,
            'title' => $title,
            'message' => $body,
            'triggered_at' => Carbon::now()->toIso8601String(),
            'triggered_by' => $context['triggered_by'] ?? null,
        ];

        $channels = [];

        $websocketChannel = $this->deliverViaWebSocket($driver, $payload);
        if ($websocketChannel) {
            $channels[] = $websocketChannel;
        }

        $pushResult = $this->pushToDriverDevices($driver, $payload, $title, $body);
        if ($pushResult['success']) {
            $channels[] = 'push';
        }

        return [
            'title' => $title,
            'body' => $body,
            'channels' => array_values(array_unique($channels)),
            'websocket_delivered' => in_array('websocket', $channels, true),
            'push_delivered' => $pushResult['success'],
            'eligible_devices' => $pushResult['eligible_devices'],
            'delivered_devices' => $pushResult['delivered_devices'],
        ];
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
        $result = $this->pushToDriverDevices(
            $driver,
            $payload,
            (string) config('services.firebase.assignment_title', 'New Booking Assigned'),
            (string) config('services.firebase.assignment_body', 'A new booking has been assigned to you.')
        );

        return $result['success'] ? 'push' : false;
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
            ->onQueue(config('services.firebase.queue', 'driver-notifications'))
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

    private function normalizePayloadForPush(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = json_encode($value);
                continue;
            }
            if (is_bool($value)) {
                $normalized[$key] = $value ? '1' : '0';
                continue;
            }
            $normalized[$key] = $value === null ? '' : (string) $value;
        }

        return $normalized;
    }

    private function pushToDriverDevices(Driver $driver, array $payload, string $title, string $body): array
    {
        try {
            $serverKey = trim((string) config('services.firebase.server_key', ''));
            if ($serverKey === '') {
                Log::warning('FCM push skipped: FIREBASE_SERVER_KEY is not configured');
                return [
                    'success' => false,
                    'eligible_devices' => 0,
                    'delivered_devices' => 0,
                ];
            }

            $devices = $driver->activeDevices()
                ->whereNotNull('push_token')
                ->get();

            $eligibleDevices = $devices->count();
            if ($eligibleDevices === 0) {
                Log::info('No push-capable device for driver ' . $driver->id);
                return [
                    'success' => false,
                    'eligible_devices' => 0,
                    'delivered_devices' => 0,
                ];
            }

            $fcmUrl = (string) config('services.firebase.fcm_send_url', 'https://fcm.googleapis.com/fcm/send');
            $delivered = 0;

            foreach ($devices as $device) {
                $response = Http::withHeaders([
                    'Authorization' => 'key=' . $serverKey,
                    'Content-Type' => 'application/json',
                ])->timeout(10)->post($fcmUrl, [
                    'to' => $device->push_token,
                    'priority' => 'high',
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                    ],
                    'data' => $this->normalizePayloadForPush($payload),
                ]);

                if (!$response->successful()) {
                    Log::warning('FCM request failed', [
                        'driver_id' => $driver->id,
                        'device_uuid' => $device->device_uuid,
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);
                    continue;
                }

                $responseData = $response->json();
                $successCount = (int) ($responseData['success'] ?? 0);

                if ($successCount > 0) {
                    $delivered++;
                    continue;
                }

                $errorCode = $responseData['results'][0]['error'] ?? null;
                if (in_array($errorCode, ['InvalidRegistration', 'NotRegistered'], true)) {
                    $device->update(['push_token' => null]);
                }

                Log::warning('FCM delivery returned failure', [
                    'driver_id' => $driver->id,
                    'device_uuid' => $device->device_uuid,
                    'error_code' => $errorCode,
                    'response' => $responseData,
                ]);
            }

            return [
                'success' => $delivered > 0,
                'eligible_devices' => $eligibleDevices,
                'delivered_devices' => $delivered,
            ];
        } catch (\Exception $e) {
            Log::warning('Push delivery failed for driver ' . $driver->id, [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'eligible_devices' => 0,
                'delivered_devices' => 0,
            ];
        }
    }
}
