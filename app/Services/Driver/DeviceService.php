<?php

namespace App\Services\Driver;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DeviceService
 * 
 * Service for managing driver mobile device registrations.
 * Handles device registration, updates, and push token management.
 * 
 * @see Requirement 3.1 - Device UUID generation and storage
 */
class DeviceService
{
    /**
     * Register or update a device for a driver.
     * 
     * If a device with the same UUID already exists for the driver,
     * it will be updated. Otherwise, a new device record is created.
     * 
     * @param Driver $driver The driver registering the device
     * @param array $deviceData Device information
     * @return DriverDevice The registered/updated device
     */
    public function registerDevice(Driver $driver, array $deviceData): DriverDevice
    {
        $deviceUuid = $deviceData['device_uuid'];
        $now = now();
        
        Log::info('Attempting to register/update device', [
            'driver_id' => $driver->id,
            'device_uuid' => $deviceUuid,
        ]);
        
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
        
        try {
            // Use updateOrCreate to handle both insert and update cases
            // First, check if a soft-deleted device exists and restore it
            $existingDevice = DriverDevice::withTrashed()
                ->where('driver_id', $driver->id)
                ->where('device_uuid', $deviceUuid)
                ->first();
            
            if ($existingDevice && $existingDevice->trashed()) {
                Log::info('Restoring soft-deleted device', [
                    'driver_id' => $driver->id,
                    'device_id' => $existingDevice->id,
                    'device_uuid' => $deviceUuid,
                ]);
                $existingDevice->restore();
                $existingDevice->update($updateData);
                $device = $existingDevice;
            } else {
                // Use updateOrCreate for non-trashed devices
                $device = DriverDevice::updateOrCreate(
                    [
                        'driver_id' => $driver->id,
                        'device_uuid' => $deviceUuid,
                    ],
                    array_merge($updateData, [
                        'registered_at' => $existingDevice->registered_at ?? $now,
                    ])
                );
            }
            
            Log::info('Driver device registered/updated', [
                'driver_id' => $driver->id,
                'device_id' => $device->id,
                'device_uuid' => $deviceUuid,
                'platform' => $device->platform,
                'was_recently_created' => $device->wasRecentlyCreated,
            ]);
            
            return $device;
        } catch (\Exception $e) {
            Log::error('Failed to register/update device', [
                'driver_id' => $driver->id,
                'device_uuid' => $deviceUuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Update push token for a device.
     * 
     * @param Driver $driver The driver
     * @param string $deviceUuid The device UUID
     * @param string $pushToken The new push token
     * @param string|null $pushProvider The push provider (fcm, apns)
     * @return DriverDevice|null The updated device or null if not found
     */
    public function updatePushToken(
        Driver $driver, 
        string $deviceUuid, 
        string $pushToken, 
        ?string $pushProvider = null
    ): ?DriverDevice {
        $device = DriverDevice::where('driver_id', $driver->id)
            ->where('device_uuid', $deviceUuid)
            ->first();
        
        if (!$device) {
            Log::warning('Device not found for push token update', [
                'driver_id' => $driver->id,
                'device_uuid' => $deviceUuid,
            ]);
            return null;
        }
        
        $device->update([
            'push_token' => $pushToken,
            'push_provider' => $pushProvider ?? $device->push_provider,
            'last_active_at' => now(),
        ]);
        
        Log::info('Push token updated', [
            'driver_id' => $driver->id,
            'device_id' => $device->id,
            'push_provider' => $device->push_provider,
        ]);
        
        return $device;
    }

    /**
     * Update device activity timestamp.
     * 
     * @param Driver $driver The driver
     * @param string $deviceUuid The device UUID
     * @return DriverDevice|null The updated device or null if not found
     */
    public function touchDevice(Driver $driver, string $deviceUuid): ?DriverDevice
    {
        $device = DriverDevice::where('driver_id', $driver->id)
            ->where('device_uuid', $deviceUuid)
            ->first();
        
        if ($device) {
            $device->update(['last_active_at' => now()]);
        }
        
        return $device;
    }

    /**
     * Deactivate a device.
     * 
     * @param Driver $driver The driver
     * @param string $deviceUuid The device UUID
     * @return bool Whether the device was deactivated
     */
    public function deactivateDevice(Driver $driver, string $deviceUuid): bool
    {
        $device = DriverDevice::where('driver_id', $driver->id)
            ->where('device_uuid', $deviceUuid)
            ->first();
        
        if (!$device) {
            return false;
        }
        
        $device->update([
            'is_active' => false,
            'push_token' => null, // Clear push token on deactivation
        ]);
        
        Log::info('Driver device deactivated', [
            'driver_id' => $driver->id,
            'device_id' => $device->id,
            'device_uuid' => $deviceUuid,
        ]);
        
        return true;
    }

    /**
     * Deactivate all devices for a driver except the current one.
     * Used for single-session enforcement.
     * 
     * @param Driver $driver The driver
     * @param string|null $exceptDeviceUuid Device UUID to keep active
     * @return int Number of devices deactivated
     */
    public function deactivateOtherDevices(Driver $driver, ?string $exceptDeviceUuid = null): int
    {
        $query = DriverDevice::where('driver_id', $driver->id)
            ->where('is_active', true);
        
        if ($exceptDeviceUuid) {
            $query->where('device_uuid', '!=', $exceptDeviceUuid);
        }
        
        $count = $query->update([
            'is_active' => false,
            'push_token' => null,
        ]);
        
        if ($count > 0) {
            Log::info('Other driver devices deactivated', [
                'driver_id' => $driver->id,
                'deactivated_count' => $count,
                'kept_device_uuid' => $exceptDeviceUuid,
            ]);
        }
        
        return $count;
    }

    /**
     * Get all devices for a driver.
     * 
     * @param Driver $driver The driver
     * @param bool $activeOnly Whether to return only active devices
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getDriverDevices(Driver $driver, bool $activeOnly = false)
    {
        $query = DriverDevice::where('driver_id', $driver->id)
            ->orderBy('last_active_at', 'desc');
        
        if ($activeOnly) {
            $query->where('is_active', true);
        }
        
        return $query->get();
    }

    /**
     * Get device by UUID for a driver.
     * 
     * @param Driver $driver The driver
     * @param string $deviceUuid The device UUID
     * @return DriverDevice|null
     */
    public function getDevice(Driver $driver, string $deviceUuid): ?DriverDevice
    {
        return DriverDevice::where('driver_id', $driver->id)
            ->where('device_uuid', $deviceUuid)
            ->first();
    }

    /**
     * Get devices with push tokens for sending notifications.
     * 
     * @param Driver $driver The driver
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getDevicesWithPushTokens(Driver $driver)
    {
        return DriverDevice::where('driver_id', $driver->id)
            ->where('is_active', true)
            ->whereNotNull('push_token')
            ->get();
    }

    /**
     * Remove a device (soft delete).
     * 
     * @param Driver $driver The driver
     * @param string $deviceUuid The device UUID
     * @return bool Whether the device was removed
     */
    public function removeDevice(Driver $driver, string $deviceUuid): bool
    {
        $device = DriverDevice::where('driver_id', $driver->id)
            ->where('device_uuid', $deviceUuid)
            ->first();
        
        if (!$device) {
            return false;
        }
        
        $device->delete();
        
        Log::info('Driver device removed', [
            'driver_id' => $driver->id,
            'device_id' => $device->id,
            'device_uuid' => $deviceUuid,
        ]);
        
        return true;
    }
}
