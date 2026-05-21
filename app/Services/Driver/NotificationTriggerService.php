<?php

namespace App\Services\Driver;

use App\Events\AssignmentCreated;
use App\Jobs\SendAssignmentNotificationJob;
use App\Models\Booking\BookingDispatch;
use App\Models\Driver\Driver;
use App\Models\DriverAssignment;
use Carbon\Carbon;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Notifications\DatabaseNotification;

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
    private const FIREBASE_MESSAGING_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * Send assignment notification to the assigned driver.
     *
     * Builds the payload, attempts WebSocket then push delivery,
     * records success, or queues a retry job on failure.
     */
    public function sendAssignmentNotification(DriverAssignment $assignment): void
    {
        SendAssignmentNotificationJob::dispatch($assignment, 1, [])
            ->onQueue(config('services.firebase.queue', 'driver-notifications'));

        Log::info('Queued assignment notification', [
            'assignment_id' => $assignment->id,
            'queue' => config('services.firebase.queue', 'driver-notifications'),
        ]);
    }

    /**
     * Process one queued notification delivery attempt.
     */
    public function processAssignmentNotificationAttempt(DriverAssignment $assignment, int $attempt = 1, array $context = []): void
    {
        $assignment->loadMissing(['driver', 'booking', 'bookingItem']);

        $driver = $assignment->driver;
        if (!$driver) {
            Log::warning('NotificationTriggerService: No driver on assignment ' . $assignment->id);
            return;
        }

        $payload = $this->buildPayload($assignment, $context);
        $this->storeDriverNotification(
            $driver,
            $payload,
            (string) config('services.firebase.assignment_title', 'New Booking Assigned'),
            (string) config('services.firebase.assignment_body', 'A new booking has been assigned to you.')
        );

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
        $body = trim((string) ($context['body'] ?? 'This is a test notification from TheTaxi admin portal.'));

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
        $bookingItem = $assignment->bookingItem;
        $dispatch = BookingDispatch::where('booking_id', $assignment->booking_id)->latest('updated_at')->first();

        return array_merge([
            'event_type' => 'assignment_created',
            'notification_type' => 'assignment_created',
            'booking_id' => $assignment->booking_id,
            'booking_item_id' => $assignment->booking_item_id,
            'assignment_id' => $assignment->id,
            'driver_id' => $assignment->driver_id,
            'pickup_location' => $bookingItem?->pickup_location,
            'dropoff_location' => $bookingItem?->dropoff_location,
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

    private function storeDriverNotification(Driver $driver, array $payload, string $title, string $message): void
    {
        try {
            $driver->loadMissing('user');

            if (!$driver->user) {
                Log::warning('Driver notification inbox persistence skipped: driver has no user', [
                    'driver_id' => $driver->id,
                ]);
                return;
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
                return;
            }

            DatabaseNotification::create([
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
        } catch (\Throwable $e) {
            Log::warning('Failed to persist driver notification inbox item', [
                'driver_id' => $driver->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveFirebaseCredentialsPath(): ?string
    {
        $configuredPath = trim((string) config('services.firebase.credentials', ''));
        if ($configuredPath === '') {
            return null;
        }

        if (is_file($configuredPath)) {
            return $configuredPath;
        }

        $storageRelativePath = storage_path(ltrim(str_replace(['storage\\', 'storage/'], '', $configuredPath), '\\/'));
        if (is_file($storageRelativePath)) {
            return $storageRelativePath;
        }

        $baseRelativePath = base_path($configuredPath);
        if (is_file($baseRelativePath)) {
            return $baseRelativePath;
        }

        return null;
    }

    private function getFirebaseCredentials(): ?array
    {
        $credentialsPath = $this->resolveFirebaseCredentialsPath();
        if (!$credentialsPath) {
            Log::warning('FCM push skipped: FIREBASE_CREDENTIALS is not configured or the file was not found');
            return null;
        }

        try {
            $contents = file_get_contents($credentialsPath);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read Firebase credentials file.');
            }

            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Firebase credentials JSON is invalid.');
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::warning('FCM push skipped: failed to load Firebase credentials', [
                'path' => $credentialsPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getFirebaseProjectId(?array $credentials = null): ?string
    {
        $configuredProjectId = trim((string) config('services.firebase.project_id', ''));
        if ($configuredProjectId !== '') {
            return $configuredProjectId;
        }

        return $credentials['project_id'] ?? null;
    }

    private function getFirebaseSendUrl(string $projectId): string
    {
        $configuredUrl = trim((string) config('services.firebase.http_v1_url', ''));
        if ($configuredUrl !== '') {
            return $configuredUrl;
        }

        return sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $projectId);
    }

    private function getFirebaseAccessToken(array $credentials): ?string
    {
        $projectId = $this->getFirebaseProjectId($credentials);
        $clientEmail = $credentials['client_email'] ?? 'unknown';
        $cacheKey = 'firebase:fcm-access-token:' . md5($clientEmail . '|' . ($projectId ?? ''));

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $serviceAccount = new ServiceAccountCredentials(
                [self::FIREBASE_MESSAGING_SCOPE],
                $credentials
            );

            $tokenData = $serviceAccount->fetchAuthToken();
            $accessToken = $tokenData['access_token'] ?? null;

            if (!is_string($accessToken) || $accessToken === '') {
                Log::warning('Failed to fetch Firebase access token: access token missing', [
                    'token_data' => $tokenData,
                ]);

                return null;
            }

            $expiresIn = (int) ($tokenData['expires_in'] ?? 3600);
            $ttl = max($expiresIn - 120, 300);
            Cache::put($cacheKey, $accessToken, now()->addSeconds($ttl));

            return $accessToken;
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch Firebase access token', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function shouldInvalidatePushToken(array $errorPayload): bool
    {
        $errorCode = data_get($errorPayload, 'error.details.0.errorCode');
        $status = data_get($errorPayload, 'error.status');
        $message = (string) data_get($errorPayload, 'error.message', '');

        if (in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            return true;
        }

        if ($status === 'NOT_FOUND' && str_contains(strtolower($message), 'registration token')) {
            return true;
        }

        return false;
    }

    private function pushToDriverDevices(Driver $driver, array $payload, string $title, string $body): array
    {
        try {
            $driver->loadMissing('activeSession');

            if (!$driver->is_online || !$driver->activeSession) {
                Log::info('Skipping push delivery because driver has no active mobile session', [
                    'driver_id' => $driver->id,
                    'is_online' => $driver->is_online,
                    'current_device_uuid' => $driver->current_device_uuid,
                ]);

                return [
                    'success' => false,
                    'eligible_devices' => 0,
                    'delivered_devices' => 0,
                ];
            }

            $credentials = $this->getFirebaseCredentials();
            if (!$credentials) {
                return [
                    'success' => false,
                    'eligible_devices' => 0,
                    'delivered_devices' => 0,
                ];
            }

            $projectId = $this->getFirebaseProjectId($credentials);
            if (!$projectId) {
                Log::warning('FCM push skipped: FIREBASE_PROJECT_ID could not be resolved from config or credentials');
                return [
                    'success' => false,
                    'eligible_devices' => 0,
                    'delivered_devices' => 0,
                ];
            }

            $accessToken = $this->getFirebaseAccessToken($credentials);
            if (!$accessToken) {
                return [
                    'success' => false,
                    'eligible_devices' => 0,
                    'delivered_devices' => 0,
                ];
            }

            $sendUrl = $this->getFirebaseSendUrl($projectId);
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
                Log::info('No push-capable device for the current active driver session', [
                    'driver_id' => $driver->id,
                    'target_device_uuid' => $targetDeviceUuid,
                    'session_device_uuid' => $sessionDeviceUuid,
                ]);
                return [
                    'success' => false,
                    'eligible_devices' => 0,
                    'delivered_devices' => 0,
                ];
            }

            $delivered = 0;
            $normalizedPayload = $this->normalizePayloadForPush($payload);

            foreach ($devices as $device) {
                if (!in_array($device->push_provider, [null, '', 'fcm'], true)) {
                    Log::info('Skipping non-FCM push token', [
                        'driver_id' => $driver->id,
                        'device_uuid' => $device->device_uuid,
                        'push_provider' => $device->push_provider,
                    ]);
                    continue;
                }

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ])->timeout(10)->post($sendUrl, [
                    'message' => [
                        'token' => $device->push_token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $normalizedPayload,
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'sound' => 'default',
                            ],
                        ],
                        'apns' => [
                            'headers' => [
                                'apns-priority' => '10',
                            ],
                            'payload' => [
                                'aps' => [
                                    'sound' => 'default',
                                    'content-available' => 1,
                                ],
                            ],
                        ],
                    ],
                ]);

                if (!$response->successful()) {
                    $responseData = $response->json() ?? ['raw' => $response->body()];
                    if ($this->shouldInvalidatePushToken($responseData)) {
                        $device->update(['push_token' => null]);
                    }

                    Log::warning('FCM request failed', [
                        'driver_id' => $driver->id,
                        'device_uuid' => $device->device_uuid,
                        'status' => $response->status(),
                        'response' => $responseData,
                    ]);
                    continue;
                }

                $responseData = $response->json() ?? [];
                if (!empty($responseData['name'])) {
                    $delivered++;
                    continue;
                }

                if ($this->shouldInvalidatePushToken($responseData)) {
                    $device->update(['push_token' => null]);
                }

                Log::warning('FCM delivery returned failure', [
                    'driver_id' => $driver->id,
                    'device_uuid' => $device->device_uuid,
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
