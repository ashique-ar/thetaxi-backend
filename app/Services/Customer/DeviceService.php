<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\CustomerDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DeviceService
 *
 * Manages rider (customer) mobile device registrations, mirroring
 * App\Services\Driver\DeviceService so the rider app can register a push
 * token the same way the driver app does.
 */
class DeviceService
{
    /**
     * Register or update a device for a customer.
     */
    public function registerDevice(Customer $customer, array $deviceData): CustomerDevice
    {
        $now = now();
        $deviceUuid = $deviceData['device_uuid'] ?? null;
        $deviceFingerprint = $deviceData['device_fingerprint'] ?? null;

        $updateData = [
            'device_name' => $deviceData['device_name'] ?? null,
            'device_model' => $deviceData['device_model'] ?? null,
            'device_manufacturer' => $deviceData['device_manufacturer'] ?? null,
            'platform' => $deviceData['platform'] ?? 'unknown',
            'os_version' => $deviceData['os_version'] ?? null,
            'app_version' => $deviceData['app_version'] ?? null,
            'app_build' => $deviceData['app_build'] ?? null,
            'push_token' => $deviceData['push_token'] ?? null,
            'push_provider' => $deviceData['push_provider'] ?? null,
            'ip_address' => $deviceData['ip_address'] ?? null,
            'locale' => $deviceData['locale'] ?? null,
            'timezone' => $deviceData['timezone'] ?? null,
            'metadata' => $deviceData['metadata'] ?? null,
            'is_active' => true,
            'last_active_at' => $now,
        ];

        if ($deviceFingerprint) {
            $updateData['device_fingerprint'] = $deviceFingerprint;
        }

        return DB::transaction(function () use ($customer, $deviceUuid, $deviceFingerprint, $updateData, $now) {
            $existingDevice = null;

            if ($deviceUuid) {
                $existingDevice = CustomerDevice::withTrashed()
                    ->where('customer_id', $customer->id)
                    ->where('device_uuid', $deviceUuid)
                    ->lockForUpdate()
                    ->first();
            }

            if (!$existingDevice && $deviceFingerprint) {
                $existingDevice = CustomerDevice::withTrashed()
                    ->where('customer_id', $customer->id)
                    ->where('device_fingerprint', $deviceFingerprint)
                    ->lockForUpdate()
                    ->first();
            }

            if ($existingDevice) {
                if ($existingDevice->trashed()) {
                    $existingDevice->restore();
                }

                $existingDevice->update($updateData);
                $device = $existingDevice;
            } else {
                $device = CustomerDevice::create(array_merge($updateData, [
                    'customer_id' => $customer->id,
                    'device_uuid' => $deviceUuid ?: Str::uuid()->toString(),
                    'registered_at' => $now,
                ]));
            }


            return $device;
        });
    }

    /**
     * Update push token for a device.
     */
    public function updatePushToken(
        Customer $customer,
        string $deviceUuid,
        string $pushToken,
        ?string $pushProvider = null
    ): ?CustomerDevice {
        $device = CustomerDevice::where('customer_id', $customer->id)
            ->where('device_uuid', $deviceUuid)
            ->first();

        if (!$device) {
            Log::warning('Device not found for push token update', [
                'customer_id' => $customer->id,
                'device_uuid' => $deviceUuid,
            ]);
            return null;
        }

        $device->update([
            'push_token' => $pushToken,
            'push_provider' => $pushProvider ?? $device->push_provider,
            'is_active' => true,
            'last_active_at' => now(),
        ]);

        return $device;
    }

    /**
     * Deactivate a device.
     */
    public function deactivateDevice(Customer $customer, string $deviceUuid): bool
    {
        $device = CustomerDevice::where('customer_id', $customer->id)
            ->where('device_uuid', $deviceUuid)
            ->first();

        if (!$device) {
            return false;
        }

        $device->update([
            'is_active' => false,
            'push_token' => null,
        ]);

        return true;
    }

    /**
     * Get all devices for a customer.
     */
    public function getCustomerDevices(Customer $customer, bool $activeOnly = false)
    {
        $query = CustomerDevice::where('customer_id', $customer->id)
            ->orderBy('last_active_at', 'desc');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Remove a device (soft delete).
     */
    public function removeDevice(Customer $customer, string $deviceUuid): bool
    {
        $device = CustomerDevice::where('customer_id', $customer->id)
            ->where('device_uuid', $deviceUuid)
            ->first();

        if (!$device) {
            return false;
        }

        $device->delete();

        return true;
    }
}
