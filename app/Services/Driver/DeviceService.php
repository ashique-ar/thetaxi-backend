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
     * If device_uuid is provided, uses it. Otherwise generates a new UUID.
     * Can identify existing devices by fingerprint to prevent duplicates.
     * 
     * @param Driver $driver The driver registering the device
     * @param array $deviceData Device information
     * @return DriverDevice The registered/updated device
     */
    public function registerDevice(Driver $driver, array $deviceData): DriverDevice
    {
        $now = now();
        
        // Generate device UUID if not provided
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
            'ip_address' => $deviceData['ip_address'] ?? null,
            'locale' => $deviceData['locale'] ?? null,
            'timezone' => $deviceData['timezone'] ?? null,
            'metadata' => $deviceData['metadata'] ?? null,
            'is_active' => true,
            'last_active_at' => $now,
        ];

        // A normal device refresh often omits FCM fields. Do not erase a
        // previously registered token; explicit deactivation owns clearing it.
        if (!empty($deviceData['push_token'])) {
            $updateData['push_token'] = $deviceData['push_token'];
        }
        if (!empty($deviceData['push_provider'])) {
            $updateData['push_provider'] = $deviceData['push_provider'];
        }
        
        // Add fingerprint if provided
        if ($deviceFingerprint) {
            $updateData['device_fingerprint'] = $deviceFingerprint;
        }
        
        // Use transaction to prevent race conditions
        return DB::transaction(function () use ($driver, $deviceUuid, $deviceFingerprint, $updateData, $now) {
            try {
                $existingDevice = null;
                
                // Strategy 1: Try to find by device_uuid if provided
                if ($deviceUuid) {
                    $existingDevice = DriverDevice::withTrashed()
                        ->where('driver_id', $driver->id)
                        ->where('device_uuid', $deviceUuid)
                        ->lockForUpdate()
                        ->first();
                }
                
                // Strategy 2: Try to find by fingerprint if no UUID match
                if (!$existingDevice && $deviceFingerprint) {
                    $existingDevice = DriverDevice::withTrashed()
                        ->where('driver_id', $driver->id)
                        ->where('device_fingerprint', $deviceFingerprint)
                        ->lockForUpdate()
                        ->first();
                    
                }
                
                if ($existingDevice) {
                    // Restore if soft-deleted
                    if ($existingDevice->trashed()) {
                        $existingDevice->restore();
                    }
                    
                    // Update existing device (keep original UUID)
                    $existingDevice->update($updateData);
                    $device = $existingDevice;
                    
                } else {
                    // Create new device with generated UUID
                    $newDeviceUuid = $deviceUuid ?: \Illuminate\Support\Str::uuid()->toString();
                    
                    $device = DriverDevice::create(array_merge($updateData, [
                        'driver_id' => $driver->id,
                        'device_uuid' => $newDeviceUuid,
                        'registered_at' => $now,
                    ]));
                    
                }
                
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
        });
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
     * Update device details captured during session start without clearing
     * existing values that were not included in the session payload.
     */
    public function updateDeviceDetailsFromSession(
        Driver $driver,
        string $deviceUuid,
        array $deviceData
    ): ?DriverDevice {
        $allowedFields = [
            'device_name',
            'device_model',
            'device_manufacturer',
            'platform',
            'os_version',
            'app_version',
            'app_build',
            'push_token',
            'push_provider',
            'ip_address',
            'locale',
            'timezone',
            'metadata',
        ];

        $updateData = [];
        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $deviceData)) {
                continue;
            }

            $value = $deviceData[$field];
            if ($value === null || $value === '') {
                continue;
            }

            $updateData[$field] = $value;
        }

        $updateData['is_active'] = true;
        $updateData['last_active_at'] = now();

        $device = DriverDevice::withTrashed()
            ->where('driver_id', $driver->id)
            ->where('device_uuid', $deviceUuid)
            ->first();

        if ($device) {
            if ($device->trashed()) {
                $device->restore();
            }

            $device->update($updateData);
            return $device;
        }

        return DriverDevice::create(array_merge($updateData, [
            'driver_id' => $driver->id,
            'device_uuid' => $deviceUuid,
            'platform' => $updateData['platform'] ?? 'unknown',
            'registered_at' => now(),
        ]));
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
        
        
        return true;
    }
}
