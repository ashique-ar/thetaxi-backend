<?php

namespace App\Models\Driver;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DriverDevice Model
 * 
 * Represents a mobile device registered by a driver for using the driver app.
 * Tracks device information, app version, push tokens, and activity.
 * 
 * @property string $id Primary key (UUID)
 * @property string $driver_id Foreign key to drivers table
 * @property string $device_uuid Unique device identifier from mobile app
 * @property string|null $device_name User-friendly device name
 * @property string|null $device_model Device model (e.g., "iPhone 14 Pro")
 * @property string|null $device_manufacturer Device manufacturer (e.g., "Apple")
 * @property string $platform OS platform: ios, android
 * @property string|null $os_version OS version
 * @property string|null $app_version Mobile app version
 * @property string|null $app_build App build number
 * @property string|null $push_token Push notification token
 * @property string|null $push_provider Push provider: fcm, apns
 * @property bool $is_active Whether device is currently active
 * @property \Carbon\Carbon|null $last_active_at Last activity timestamp
 * @property \Carbon\Carbon $registered_at When device was first registered
 * @property string|null $ip_address Last known IP address
 * @property string|null $locale Device locale
 * @property string|null $timezone Device timezone
 * @property array|null $metadata Additional device metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * 
 * @property-read Driver $driver
 * 
 * @see Requirement 3.1 - Device UUID generation and storage
 */
class DriverDevice extends BaseModel
{
    use SoftDeletes;

    protected $table = 'driver_devices';

    protected $fillable = [
        'driver_id',
        'device_uuid',
        'device_name',
        'device_model',
        'device_manufacturer',
        'platform',
        'os_version',
        'app_version',
        'app_build',
        'push_token',
        'push_provider',
        'is_active',
        'last_active_at',
        'registered_at',
        'ip_address',
        'locale',
        'timezone',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_active_at' => 'datetime',
        'registered_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get the driver that owns this device.
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Scope to get only active devices.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get devices by platform.
     */
    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope to get iOS devices.
     */
    public function scopeIos($query)
    {
        return $query->where('platform', 'ios');
    }

    /**
     * Scope to get Android devices.
     */
    public function scopeAndroid($query)
    {
        return $query->where('platform', 'android');
    }

    /**
     * Scope to get devices with push tokens.
     */
    public function scopeWithPushToken($query)
    {
        return $query->whereNotNull('push_token');
    }

    /**
     * Check if this is an iOS device.
     */
    public function isIos(): bool
    {
        return $this->platform === 'ios';
    }

    /**
     * Check if this is an Android device.
     */
    public function isAndroid(): bool
    {
        return $this->platform === 'android';
    }

    /**
     * Check if device has a valid push token.
     */
    public function hasPushToken(): bool
    {
        return !empty($this->push_token);
    }

    /**
     * Update the last active timestamp.
     */
    public function touch(): bool
    {
        $this->last_active_at = now();
        return $this->save();
    }

    /**
     * Deactivate this device.
     */
    public function deactivate(): bool
    {
        $this->is_active = false;
        return $this->save();
    }

    /**
     * Activate this device.
     */
    public function activate(): bool
    {
        $this->is_active = true;
        $this->last_active_at = now();
        return $this->save();
    }

    /**
     * Get a display name for the device.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->device_name) {
            return $this->device_name;
        }

        if ($this->device_model) {
            return $this->device_model;
        }

        return ucfirst($this->platform) . ' Device';
    }

    /**
     * Get a formatted platform and version string.
     */
    public function getPlatformDisplayAttribute(): string
    {
        $platform = ucfirst($this->platform);
        
        if ($this->os_version) {
            return "{$platform} {$this->os_version}";
        }

        return $platform;
    }
}
