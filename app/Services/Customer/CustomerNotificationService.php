<?php

namespace App\Services\Customer;

use App\Models\Booking\Booking;
use App\Models\CustomerDevice;
use App\Models\Driver\Driver;
use App\Services\Fcm\FcmMessageService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends real-time notifications to riders (customers) for booking events.
 * Mirrors App\Services\Driver\NotificationTriggerService, reusing the same
 * FCM HTTP v1 client for push delivery.
 */
class CustomerNotificationService
{
    public function __construct(
        private readonly FcmMessageService $fcm = new FcmMessageService(),
    ) {}

    /**
     * Notify the customer that a driver has been assigned to their booking.
     */
    public function notifyDriverAssigned(Booking $booking, Driver $driver): void
    {
        $booking->loadMissing('customer.user');
        $customer = $booking->customer;

        if (!$customer || !$customer->user) {
            Log::info('Skipping rider driver-assigned notification: booking has no linked customer user', [
                'booking_id' => $booking->id,
            ]);
            return;
        }

        $driver->loadMissing('user');
        $driverName = $driver->full_name ?? trim(($driver->user->first_name ?? '') . ' ' . ($driver->user->last_name ?? '')) ?: 'Your driver';

        $bookingNumber = $booking->booking_number;
        $titleBase = (string) config('services.firebase.rider_assignment_title', 'Driver Assigned');
        $title = $bookingNumber ? "{$titleBase} - {$bookingNumber}" : $titleBase;

        $defaultBody = (string) config('services.firebase.rider_assignment_body', 'A driver has been assigned to your booking.');
        $body = trim("{$driverName} has been assigned as your driver" . ($bookingNumber ? " for booking {$bookingNumber}." : '.'));
        if ($body === '') {
            $body = $defaultBody;
        }

        $payload = [
            'event_type' => 'driver_assigned',
            'notification_type' => 'driver_assigned',
            'booking_id' => $booking->id,
            'booking_number' => $bookingNumber,
            'driver_id' => $driver->id,
            'driver_name' => $driverName,
        ];

        $this->storeCustomerNotification($customer->user, $payload, $title, $body);
        $this->pushToCustomerDevices($customer->id, $payload, $title, $body);
    }

    private function storeCustomerNotification($user, array $payload, string $title, string $message): void
    {
        try {
            if (
                DatabaseNotification::where('notifiable_type', $user->getMorphClass())
                    ->where('notifiable_id', $user->id)
                    ->where('data->notification_type', 'driver_assigned')
                    ->where('data->data->booking_id', $payload['booking_id'])
                    ->where('data->data->driver_id', $payload['driver_id'])
                    ->exists()
            ) {
                return;
            }

            DatabaseNotification::create([
                'id' => (string) Str::uuid(),
                'type' => 'customer_mobile',
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => [
                    'title' => $title,
                    'message' => $message,
                    'type' => $payload['notification_type'],
                    'notification_type' => $payload['notification_type'],
                    'data' => $payload,
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist customer notification inbox item', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function pushToCustomerDevices(string $customerId, array $payload, string $title, string $body): array
    {
        try {
            $devices = CustomerDevice::query()
                ->where('customer_id', $customerId)
                ->where('is_active', true)
                ->whereNotNull('push_token')
                ->get();

            $eligibleDevices = $devices->count();
            if ($eligibleDevices === 0) {
                Log::info('No push-capable device for customer', ['customer_id' => $customerId]);
                return ['success' => false, 'eligible_devices' => 0, 'delivered_devices' => 0];
            }

            $session = $this->fcm->prepareSession();
            if (!$session) {
                return ['success' => false, 'eligible_devices' => $eligibleDevices, 'delivered_devices' => 0];
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
                    'customer_id' => $customerId,
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
            Log::warning('Push delivery failed for customer ' . $customerId, [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'eligible_devices' => 0, 'delivered_devices' => 0];
        }
    }
}
