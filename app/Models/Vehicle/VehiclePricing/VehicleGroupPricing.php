<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Models\Vehicle\VehicleGroup;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleGroupPricing extends BaseModel
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'vehicle_group_pricing';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'slab_definition_id',
        'vehicle_group_id',
        'rate',
        'rate_type',
        'minimum_charge',
        'includes_fuel',
        'includes_driver',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'rate' => 'decimal:2',
        'minimum_charge' => 'decimal:2',
        'includes_fuel' => 'boolean',
        'includes_driver' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get the slab definition that owns the pricing.
     */
    public function slabDefinition(): BelongsTo
    {
        return $this->belongsTo(VehiclePricingSlabDefinition::class, 'slab_definition_id');
    }

    /**
     * Get the vehicle group that owns the pricing.
     */
    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class, 'vehicle_group_id');
    }

    /**
     * Get the pricing calculations using this group pricing.
     */
    public function pricingCalculations(): HasMany
    {
        return $this->hasMany(PricingCalculation::class, 'vehicle_group_id', 'vehicle_group_id')
                    ->where('slab_definition_id', $this->slab_definition_id);
    }

    /**
     * Scope a query to only include active pricing.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by vehicle group.
     */
    public function scopeForVehicleGroup($query, $vehicleGroupId)
    {
        return $query->where('vehicle_group_id', $vehicleGroupId);
    }

    /**
     * Scope a query to filter by slab definition.
     */
    public function scopeForSlabDefinition($query, $slabDefinitionId)
    {
        return $query->where('slab_definition_id', $slabDefinitionId);
    }


}
