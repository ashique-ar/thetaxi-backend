<?php

namespace App\Services\Driver;

use App\Events\AssignmentCreated;
use App\Jobs\SendAssignmentNotificationJob;
use App\Jobs\SendDriverAssignmentFallbackSmsJob;
use App\Models\Booking\BookingDispatch;
use App\Models\Driver\Driver;
use App\Models\DriverAssignment;
use App\Models\Driver\DriverAssignmentNotification;
use App\Services\Sms\SmsAutomationService;
use App\Services\Sms\SmsSettingsService;
use App\Services\Fcm\FcmMessageService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

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
    public function __construct(
        private readonly FcmMessageService $fcm = new FcmMessageService(),
        private readonly ?SmsSettingsService $smsSettings = null,
        private readonly ?SmsAutomationService $smsAutomation = null,
    ) {}

    /**
     * Send assignment notification to the assigned driver.
     *
     * Builds the payload, attempts WebSocket then push delivery,
     * records success, or queues a retry job on failure.
     */
    public function sendAssignmentNotification(DriverAssignment $assignment): string
    {
        $tracking = DriverAssignmentNotification::query()->firstOrCreate(
            ['assignment_id' => $assignment->id, 'driver_id' => $assignment->driver_id],
            ['fallback_due_at' => now()->addMinutes($this->smsSettings?->getSettings()['driver_assignment_fallback_timeout_minutes'] ?? 10)]
        );

        // Send immediately on current request thread for instant mobile arrival
        try {
            $this->processAssignmentNotificationAttempt($assignment, 1, ['notification_id' => $tracking->id]);
        } catch (\Throwable $e) {
            Log::warning('Instant notification delivery failed, queueing job', [
                'assignment_id' => $assignment->id,
                'error' => $e->getMessage(),
            ]);
            SendAssignmentNotificationJob::dispatch($assignment, 1, ['notification_id' => $tracking->id])
                ->onQueue(config('services.firebase.queue', 'driver-notifications'));
        }

        SendDriverAssignmentFallbackSmsJob::dispatch($tracking->id)
            ->delay($tracking->fallback_due_at);


        return (string) $tracking->id;
    }

    /**
     * Process one queued notification delivery attempt.
     */
    public function processAssignmentNotificationAttempt(DriverAssignment $assignment, int $attempt = 1, array $context = []): void
    {
        $assignment->loadMissing(['driver', 'booking.customer.user', 'bookingItem']);

        $driver = $assignment->driver;
        if (!$driver) {
            Log::warning('NotificationTriggerService: No driver on assignment ' . $assignment->id);
            return;
        }

        $payload = $this->buildPayload($assignment, $context);
        $message = $this->buildAssignmentMessage($assignment, $payload);
        $payload['title'] = $message['title'];
        $payload['message'] = $message['body'];

        $databaseNotificationId = $this->storeDriverNotification(
            $driver,
            $payload,
            $message['title'],
            $message['body']
        );
        if (!empty($payload['notification_id'])) {
            DriverAssignmentNotification::query()->whereKey($payload['notification_id'])->update([
                'database_notification_id' => $databaseNotificationId,
            ]);
        }

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
            if (!empty($payload['notification_id'])) {
                DriverAssignmentNotification::query()->whereKey($payload['notification_id'])->update([
                    'delivered_at' => now(),
                    'delivery_channel' => implode('+', array_unique($channels)),
                ]);
            }
            return;
        }

        $this->queueRetry($assignment, $attempt + 1, $context);
    }

    /**
     * Send a manual test notification to a driver's mobile app/devices.
     *
     * Used by admin portal "test notification" action in driver detail page.
     */
    public function sendDriverTestNotification(Driver $driver, array $context = []): array
    {
        $title = trim((string) ($context['title'] ?? 'Driver Notification Test'));
        $body = trim((string) ($context['body'] ?? 'This is a test notification from the admin portal.'));

        $payload = [
            'event_type' => 'driver_test_notification',
            'notification_type' => 'driver_test_notification',
            'driver_id' => $driver->id,
            'title' => $title,
            'message' => $body,
            'triggered_at' => Carbon::now()->toIso8601String(),
            'triggered_by' => $context['triggered_by'] ?? null,
        ];

        $this->storeDriverNotification($driver, $payload, $title, $body);

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
    public function buildPayload(DriverAssignment $assignment, array $context = []): array
    {
        $assignment->loadMissing(['booking.customer.user', 'bookingItem']);

        $booking = $assignment->booking;
        $bookingItem = $assignment->bookingItem;
        $dispatch = BookingDispatch::where('booking_id', $assignment->booking_id)->latest('updated_at')->first();

        return array_merge([
            'event_type' => 'assignment_created',
            'notification_type' => 'assignment_created',
            'booking_id' => $assignment->booking_id,
            'booking_item_id' => $assignment->booking_item_id,
            'assignment_id' => $assignment->id,
            'driver_id' => $assignment->driver_id,
            'booking_number' => $booking?->booking_number,
            'customer_name' => $this->resolveCustomerName($assignment),
            'pickup_location' => $bookingItem?->pickup_location,
            'dropoff_location' => $bookingItem?->dropoff_location,
            'pickup_location_label' => $this->extractLocationLabel($bookingItem?->pickup_location),
            'dropoff_location_label' => $this->extractLocationLabel($bookingItem?->dropoff_location),
            'scheduled_datetime' => $assignment->assigned_from?->toIso8601String(),
            'dispatch_id' => $dispatch?->id,
            'dispatch_status' => $dispatch?->dispatch_status?->value,
            'dispatched_at' => $dispatch?->dispatched_at?->toIso8601String(),
            'dispatch_action' => $dispatch?->isActive() ? 'dispatch' : null,
            'is_repeat_dispatch' => false,
        ], $context);
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
        $title = (string) ($payload['title'] ?? config('services.firebase.assignment_title', 'New Booking Assigned'));
        $body = (string) ($payload['message'] ?? $payload['body'] ?? config('services.firebase.assignment_body', 'A new booking has been assigned to you.'));

        $result = $this->pushToDriverDevices(
            $driver,
            $payload,
            $title,
            $body
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
    public function queueRetry(DriverAssignment $assignment, int $attempt = 1, array $context = []): void
    {
        if ($attempt > SendAssignmentNotificationJob::MAX_ATTEMPTS) {
            Log::error('Notification delivery exhausted all retries for assignment ' . $assignment->id, [
                'max_attempts' => SendAssignmentNotificationJob::MAX_ATTEMPTS,
            ]);
            return;
        }

        SendAssignmentNotificationJob::dispatch($assignment, $attempt, $context)
            ->onQueue(config('services.firebase.queue', 'driver-notifications'))
            ->delay(now()->addSeconds(SendAssignmentNotificationJob::RETRY_DELAY_SECONDS));

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

        } else {
        }
    }

    public function acknowledgeAssignment(DriverAssignment $assignment, string $driverId, string $source): ?DriverAssignmentNotification
    {
        return DB::transaction(function () use ($assignment, $driverId, $source) {
            $notification = DriverAssignmentNotification::query()
                ->where('assignment_id', $assignment->id)
                ->where('driver_id', $driverId)
                ->lockForUpdate()
                ->first();
            $notification ??= DriverAssignmentNotification::query()->create([
                'assignment_id' => $assignment->id,
                'driver_id' => $driverId,
            ]);

            if (!$notification->acknowledged_at) {
                $notification->update([
                    'acknowledged_at' => now(),
                    'acknowledgement_source' => $source,
                    'fallback_result' => 'acknowledged',
                ]);
            }

            return $notification->fresh();
        });
    }

    public function processFallback(DriverAssignmentNotification $notification): void
    {
        DB::transaction(function () use ($notification): void {
            $locked = DriverAssignmentNotification::query()->lockForUpdate()->find($notification->id);
            if (!$locked || $locked->fallback_checked_at || $locked->fallback_sms_message_id) {
                return;
            }

            $assignment = DriverAssignment::query()->with(['driver.user', 'booking'])->find($locked->assignment_id);
            $result = null;
            if ($locked->acknowledged_at) {
                $result = 'skipped_acknowledged';
            } elseif (!$assignment) {
                $result = 'skipped_assignment_missing';
            } elseif ((string) $assignment->driver_id !== (string) $locked->driver_id) {
                $result = 'skipped_reassigned';
            } elseif (in_array((string) $assignment->status, ['declined', 'cancelled', 'completed'], true)) {
                $result = 'skipped_terminal';
            } elseif ($assignment->assigned_to && $assignment->assigned_to->isPast()) {
                $result = 'skipped_expired';
            } elseif (!$this->smsAutomation || !$assignment->booking) {
                $result = 'skipped_unavailable';
            }

            if ($result) {
                $locked->update(['fallback_checked_at' => now(), 'fallback_result' => $result]);
                return;
            }

            $phone = (string) ($assignment->driver?->phone ?? $assignment->driver?->user?->phone ?? '');
            $message = $this->smsAutomation->queueDriverAssignmentFallback($assignment->booking, $assignment, $phone);
            $locked->update([
                'fallback_checked_at' => now(),
                'fallback_result' => $message ? $message->status : 'skipped_disabled_or_missing_recipient',
                'fallback_sms_message_id' => $message?->id,
            ]);
        });
    }

    private function buildAssignmentMessage(DriverAssignment $assignment, array $payload): array
    {
        $bookingNumber = trim((string) ($payload['booking_number'] ?? ''));
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $pickup = trim((string) ($payload['pickup_location_label'] ?? ''));
        $dropoff = trim((string) ($payload['dropoff_location_label'] ?? ''));

        $titleBase = (string) config('services.firebase.assignment_title', 'New Booking Assigned');
        $title = $bookingNumber !== '' ? "{$titleBase} - {$bookingNumber}" : $titleBase;

        $parts = [];
        if ($bookingNumber !== '') {
            $parts[] = "Booking {$bookingNumber}";
        }
        if ($customerName !== '') {
            $parts[] = "Customer {$customerName}";
        }
        if ($pickup !== '' || $dropoff !== '') {
            $route = ($pickup !== '' ? $pickup : 'Pickup TBA') . ' to ' . ($dropoff !== '' ? $dropoff : 'Dropoff TBA');
            $parts[] = $route;
        }

        $body = !empty($parts)
            ? implode(' | ', $parts)
            : (string) config('services.firebase.assignment_body', 'A new booking has been assigned to you.');

        return [
            'title' => $title,
            'body' => $body,
        ];
    }

    private function resolveCustomerName(DriverAssignment $assignment): ?string
    {
        if (!empty($assignment->customer_name)) {
            return $assignment->customer_name;
        }

        $customerUser = $assignment->booking?->customer?->user;
        if (!$customerUser) {
            return null;
        }

        $name = trim(($customerUser->first_name ?? '') . ' ' . ($customerUser->last_name ?? ''));
        return $name !== '' ? $name : null;
    }

    private function extractLocationLabel(mixed $location): ?string
    {
        if (is_array($location)) {
            return $location['address']
                ?? $location['display_name']
                ?? $location['name']
                ?? null;
        }

        if (!is_string($location) || trim($location) === '') {
            return null;
        }

        $decoded = json_decode($location, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded['address']
                ?? $decoded['display_name']
                ?? $decoded['name']
                ?? $location;
        }

        return $location;
    }

    private function storeDriverNotification(Driver $driver, array $payload, string $title, string $message): ?string
    {
        try {
            $driver->loadMissing('user');

            if (!$driver->user) {
                Log::warning('Driver notification inbox persistence skipped: driver has no user', [
                    'driver_id' => $driver->id,
                ]);
                return null;
            }

            if (
                ($payload['notification_type'] ?? $payload['event_type'] ?? null) === 'assignment_created'
                && !empty($payload['assignment_id'])
                && DatabaseNotification::where('notifiable_type', $driver->user->getMorphClass())
                    ->where('notifiable_id', $driver->user->id)
                    ->where('data->notification_type', 'assignment_created')
                    ->where('data->data->assignment_id', $payload['assignment_id'])
                    ->exists()
            ) {
                return null;
            }

            $notification = DatabaseNotification::create([
                'id' => (string) Str::uuid(),
                'type' => 'driver_mobile',
                'notifiable_type' => $driver->user->getMorphClass(),
                'notifiable_id' => $driver->user->id,
                'data' => [
                    'title' => $title,
                    'message' => $message,
                    'type' => $payload['notification_type'] ?? $payload['event_type'] ?? 'driver_notification',
                    'notification_type' => $payload['notification_type'] ?? $payload['event_type'] ?? 'driver_notification',
                    'data' => $payload,
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return (string) $notification->id;
        } catch (\Throwable $e) {
            Log::warning('Failed to persist driver notification inbox item', [
                'driver_id' => $driver->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function pushToDriverDevices(Driver $driver, array $payload, string $title, string $body): array
    {
        try {
            $driver->loadMissing('activeSession');

            if (!$driver->is_online || !$driver->activeSession) {

                return [
                    'success' => false,
                    'eligible_devices' => 0,
                    'delivered_devices' => 0,
                ];
            }

            $session = $this->fcm->prepareSession();
            if (!$session) {
                return [
                    'success' => false,
                    'eligible_devices' => 0,
                    'delivered_devices' => 0,
                ];
            }

            $sessionDeviceUuid = $driver->activeSession->device_uuid;
            $targetDeviceUuid = $driver->current_device_uuid ?: $sessionDeviceUuid;
            $devicesQuery = $driver->activeDevices()->whereNotNull('push_token');

            if ($targetDeviceUuid) {
                $devicesQuery->where('device_uuid', $targetDeviceUuid);
            }

            if ($sessionDeviceUuid && $sessionDeviceUuid !== $targetDeviceUuid) {
                $devicesQuery->orWhere(function ($query) use ($driver, $sessionDeviceUuid) {
                    $query->where('driver_id', $driver->id)
                        ->where('is_active', true)
                        ->whereNotNull('push_token')
                        ->where('device_uuid', $sessionDeviceUuid);
                });
            }

            $devices = $devicesQuery->get();

            $eligibleDevices = $devices->count();
            if ($eligibleDevices === 0) {
                return [
                    'success' => false,
                    'eligible_devices' => 0,
                    'delivered_devices' => 0,
                ];
            }

            $delivered = 0;
            $normalizedPayload = $this->fcm->normalizeDataPayload($payload);

            foreach ($devices as $device) {
                if (!in_array($device->push_provider, [null, '', 'fcm'], true)) {
                    continue;
                }

                $result = $this->fcm->send($session, $device->push_token, $title, $body, $normalizedPayload);

                if ($result['success']) {
                    $delivered++;
                    continue;
                }

                if ($result['invalid_token']) {
                    $device->update(['push_token' => null]);
                }

                Log::warning('FCM delivery failed', [
                    'driver_id' => $driver->id,
                    'device_uuid' => $device->device_uuid,
                    'response' => $result['response'],
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
