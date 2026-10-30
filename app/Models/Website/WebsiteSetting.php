<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use App\Models\Company;
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
            if ($enabled !== false || ! $setting->company_id) return;

            $stageKeys = match ((string) $setting->type) {
                'feature_vehicle_return_management_enabled' => ['enable_return_stage', 'enable_qc_stage'],
                'assignment_enable_qc_stage' => ['enable_qc_stage'],
                'assignment_enable_maintenance_stage' => ['enable_maintenance_stage'],
                default => [],
            };
            if ($stageKeys) {
                app(\App\Services\BookingWorkflowSettingsResolver::class)
                    ->assertCanDisableStage((string) $setting->company_id, $stageKeys);
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
        'company_id',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get a setting value by type.
     */
    public static function getValue(string $type, $default = null, ?string $companyId = null)
    {
        if (func_num_args() < 3) {
            $companyId = app(\App\Services\WebsiteSettingsService::class)->resolveCurrentCompanyId();
        }

        $query = static::query()->where('type', $type);
        if ($companyId) {
            $query->where(fn ($locationScope) => \App\Services\CompanyLocationScope::constrain($locationScope, 'company_id', $companyId))
                ->orderByRaw('CASE WHEN company_id = ? THEN 0 ELSE 1 END', [$companyId]);
        } else {
            $query->whereNull('company_id');
        }
        $setting = $query->first();

        if (!$setting && $companyId) {
            $setting = static::where('type', $type)->whereNull('company_id')->first();
        }

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value by type.
     */
    public static function setValue(string $type, $value, ?string $companyId = null): void
    {
        static::updateOrCreate(
            ['type' => $type, 'company_id' => $companyId],
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
    public static function getValues(array $types, ?string $companyId = null): array
    {
        if (func_num_args() < 2) {
            $companyId = app(\App\Services\WebsiteSettingsService::class)->resolveCurrentCompanyId();
        }

        $globalSettings = static::whereIn('type', $types)
            ->whereNull('company_id')
            ->get()
            ->keyBy('type');

        $companySettings = collect();
        if ($companyId) {
            $companySettings = static::whereIn('type', $types)
                ->where(fn ($locationScope) => \App\Services\CompanyLocationScope::constrain($locationScope, 'company_id', $companyId))
                ->orderByRaw('CASE WHEN company_id = ? THEN 0 ELSE 1 END', [$companyId])
                ->get()
                ->keyBy('type');
        }
        
        $result = [];
        foreach ($types as $type) {
            $setting = $companySettings->get($type) ?? $globalSettings->get($type);
            $result[$type] = $setting ? $setting->value : null;
        }
        
        return $result;
    }
}
