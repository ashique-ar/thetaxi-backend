<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Business Setting Model
 * 
 * Represents configurable business settings and application configuration values.
 * Used to store system-wide settings that can be modified without code changes.
 * 
 * @property string $id Primary key (UUID)
 * @property string|null $type Setting type or category (e.g., 'email_config', 'payment_gateway')
 * @property string|null $value Setting value (can be JSON for complex configurations)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class BusinessSetting extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'business_settings';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'type',
        'value',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user who created this record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Get a setting value by type.
     */
    public static function getSetting(string $type): ?string
    {
        $setting = static::where('type', $type)->first();
        return $setting?->value;
    }

    /**
     * Set a setting value by type.
     */
    public static function setSetting(string $type, string $value, ?string $userId = null): self
    {
        return static::updateOrCreate(
            ['type' => $type],
            [
                'value' => $value,
                'updated_user_id' => $userId,
            ]
        );
    }
}
