<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Models\Service\ServiceType;
use App\Models\User;
use App\Models\Vehicle\VehiclePricing\Concerns\HasGlobalPricingDefinitionScope;
use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pricing Common Rate Definition Model
 * 
 * Represents baseline pricing rates that can be applied across vehicle groups.
 * These rates serve as default values that can be overridden by specific vehicle group pricing.
 * 
 * @property string $id Primary key (UUID)
 * @property string $service_type_id Foreign key to service_types table
 * @property string $vehicle_group_id Foreign key to vehicle_groups table (optional for global rates)
 * @property string $code Unique code for the rate definition
 * @property string $name Name of the rate definition
 * @property string|null $description Description of the rate definition
 * @property string $common_rate_type Type of common rate (e.g., percentage, fixed amount)
 * @property bool $is_mandatory Whether this rate is mandatory for the service type
 * @property bool $is_active Whether this rate is currently active
 * @property int $sort_order Order in which this rate should be displayed
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 */
class VehiclePricingCommonRateDefinition extends BaseModel
{
    use HasGlobalPricingDefinitionScope;

    protected static function booted(): void
    {
        static::saving(function (self $definition): void {
            $definition->vehicle_group_id = null;
        });
    }

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_pricing_common_rate_definitions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'service_type_id',
        'vehicle_group_id',
        'code',
        'name',
        'description',
        'common_rate_type',
        'display_unit',
        'owner_type',
        'owner_id',
        'priority',
        'is_mandatory',
        'is_active',
        'sort_order',
        'created_user_id',
        'updated_user_id'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'value' => 'decimal:2',
        'is_mandatory' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'sort_order' => 'integer'
    ];

    /**
     * Get the service type this rate definition applies to
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    /**
     * Get the user who created this record
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Vehicle-group rows hold the actual public/corporate values for this
     * shared definition.
     */
    public function vehicleGroupPricing(): HasMany
    {
        return $this->hasMany(VehicleGroupCommonRatePricing::class, 'common_rate_definition_id');
    }

    /**
     * Backward-compatible relationship name used by the definition API.
     */
    public function vehicleGroupAddonPricing(): HasMany
    {
        return $this->vehicleGroupPricing();
    }

    /**
     * Scope a query to only include active rate definitions
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include mandatory rate definitions
     */
    public function scopeMandatory($query)
    {
        return $query->where('is_mandatory', true);
    }

    /**
     * Scope a query for specific service type
     */
    public function scopeForServiceType($query, $serviceTypeId)
    {
        return $query->where('service_type_id', $serviceTypeId);
    }

    /**
     * Scope a query for global rates (no specific service type)
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('service_type_id');
    }

    public function calculateAmount(
        float $baseAmount,
        int $hours = 1,
        int $days = 1,
        float $kilometers = 0,
        int $minutes = 1,
        ?float $rateValue = null
    ): float {
        $rate = $rateValue ?? (float) $this->value;

        return match ($this->common_rate_type) {
            'percentage' => ($baseAmount * $rate) / 100,
            'per_hour' => $rate * $hours,
            'per_minute' => $rate * $minutes,
            'per_day' => $rate * $days,
            'per_km' => $rate * $kilometers,
            default => $rate,
        };
    }
}
