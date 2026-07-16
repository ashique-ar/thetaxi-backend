<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehiclePricing\Concerns\HasGlobalPricingDefinitionScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehiclePricingSlabDefinition extends BaseModel
{
    use HasFactory, HasGlobalPricingDefinitionScope, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'vehicle_pricing_slab_definitions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'service_type_id',
        'name',
        'min_days',
        'max_days',
        'type',
        'min_minutes',
        'max_minutes',
        'min_hours',
        'max_hours',
        'max_km_per_day',
        'max_km_per_package',
        'sort_order',
        'owner_type',
        'owner_id',
        'priority',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'min_minutes' => 'integer',
        'max_minutes' => 'integer',
        'min_hours' => 'integer',
        'max_hours' => 'integer',
        'min_days' => 'integer',
        'max_days' => 'integer',
        'max_km_per_day' => 'integer',
        'max_km_per_package' => 'integer',
        'sort_order' => 'integer',
        'priority' => 'integer',
    ];

    /**
     * Get the service type that owns the slab definition.
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    /**
     * Get the vehicle group pricing records for this slab definition.
     */
    public function vehicleGroupPricing(): HasMany
    {
        return $this->hasMany(VehicleGroupPricing::class, 'slab_definition_id');
    }


    /**
     * Scope a query to only include active slab definitions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by service type.
     */
    public function scopeForServiceType($query, $serviceTypeId)
    {
        return $query->where('service_type_id', $serviceTypeId);
    }

    public function scopeForOwner($query, ?string $ownerType, ?string $ownerId)
    {
        // Kept for callers that also scope the associated value rows. Slab
        // definitions themselves are always shared.
        return $query->whereNull('owner_type')->whereNull('owner_id');
    }

    /**
     * Check if the given hours fall within this slab's range.
     */
    public function isWithinRange(int $hours): bool
    {
        return $hours >= $this->min_hours && 
               ($this->max_hours === null || $hours <= $this->max_hours);
    }
}
