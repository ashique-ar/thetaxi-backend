<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Website setting model.
 * 
 * @property string $id
 * @property string|null $type
 * @property string|null $value
 * @property string|null $created_user_id
 * @property string|null $updated_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * 
 * @property-read User|null $createdBy
 * @property-read User|null $updatedBy
 */
class WebsiteSetting extends BaseModel
{
    protected static function booted(): void
    {
        static::saving(function (WebsiteSetting $setting): void {
            if ($setting->exists && ! $setting->isDirty('value')) return;
            $enabled = filter_var($setting->value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($enabled !== false) return;

            $stageKeys = match ((string) $setting->type) {
                'feature_vehicle_return_management_enabled' => ['enable_return_stage', 'enable_qc_stage'],
                'assignment_enable_qc_stage' => ['enable_qc_stage'],
                'assignment_enable_maintenance_stage' => ['enable_maintenance_stage'],
                default => [],
            };
            if ($stageKeys) {
                app(\App\Services\BookingWorkflowSettingsResolver::class)
                    ->assertCanDisableStage($stageKeys);
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'value',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
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
    public static function getValue(string $type, $default = null)
    {
        $setting = static::query()->where('type', $type)->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value by type.
     */
    public static function setValue(string $type, $value): void
    {
        static::updateOrCreate(
            ['type' => $type],
            ['value' => static::normalizeValue($value)]
        );
    }

    /**
     * Normalize a setting value for storage.
     */
    public static function normalizeValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value);
            return $encoded === false ? null : $encoded;
        }

        return trim((string) $value);
    }

    /**
     * Get multiple settings by types.
     */
    public static function getValues(array $types): array
    {
        $settings = static::whereIn('type', $types)
            ->get()
            ->keyBy('type');
        
        $result = [];
        foreach ($types as $type) {
            $setting = $settings->get($type);
            $result[$type] = $setting ? $setting->value : null;
        }
        
        return $result;
    }
}
