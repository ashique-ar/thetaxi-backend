<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CustomerDevice Model
 *
 * Represents a mobile device registered by a customer (rider) for using the
 * rider mobile app. Mirrors App\Models\Driver\DriverDevice so the same FCM
 * push pipeline can be reused to notify riders.
 *
 * @property string $id Primary key (UUID)
 * @property string $customer_id Foreign key to customers table
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
 *
 * @property-read Customer $customer
 */
class CustomerDevice extends BaseModel
{
    use SoftDeletes;

    protected $table = 'customer_devices';

    protected $useUserTracking = false;

    protected $fillable = [
        'customer_id',
        'device_uuid',
        'device_fingerprint',
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
     * Get the customer that owns this device.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Scope to get only active devices.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get devices with push tokens.
     */
    public function scopeWithPushToken($query)
    {
        return $query->whereNotNull('push_token');
    }

    /**
     * Check if device has a valid push token.
     */
    public function hasPushToken(): bool
    {
        return !empty($this->push_token);
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
