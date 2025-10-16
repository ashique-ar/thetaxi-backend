<?php

// app/Models/VehiclePricing/VehicleGroupCommonRatePricing.php
namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use App\Models\Vehicle\VehicleGroup;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vehicle Group Common Rate Pricing Model
 * 
 * 
 * @property string $id Primary key (UUID)
 * @property string $vehicle_group_id Foreign key to vehicle_groups table
 * @property string $common_rate_definition_id Foreign key to pricing_addons table (common rate definitions)
 * @property float|null $value
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read VehicleGroup $vehicleGroup Vehicle group this pricing applies to
 * @property-read VehiclePricingCommonRateDefinition $commonRateDefinition Common rate definition
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class VehicleGroupCommonRatePricing extends BaseModel
{
    use UUID, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_group_common_rate_pricing';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'vehicle_group_id',
        'common_rate_definition_id',
        'value',
        'is_mandatory',
        'sort_order',
        'is_active',
        'created_user_id',
        'updated_user_id'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
        'is_mandatory' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        // Set default sort order when creating
        static::creating(function ($model) {
            // if (is_null($model->sort_order)) {
            //     $maxOrder = static::where('vehicle_group_id', $model->vehicle_group_id)
            //         ->max('sort_order') ?? 0;
            //     $model->sort_order = $maxOrder + 1;
            // }
        });
    }

    /**
     * Relationship: Vehicle Group
     */
    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class, 'vehicle_group_id');
    }

    /**
     * Relationship: Common Rate Definition
     */
    public function commonRateDefinition(): BelongsTo
    {
        return $this->belongsTo(VehiclePricingCommonRateDefinition::class, 'common_rate_definition_id');
    }

    /**
     * Relationship: Created By User
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Relationship: Updated By User
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Scope: Active pricing records
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Inactive pricing records
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope: Filter by vehicle group
     */
    public function scopeForVehicleGroup(Builder $query, string $vehicleGroupId): Builder
    {
        return $query->where('vehicle_group_id', $vehicleGroupId);
    }

    /**
     * Scope: Filter by common rate definition
     */
    public function scopeForCommonRate(Builder $query, string $commonRateId): Builder
    {
        return $query->where('common_rate_definition_id', $commonRateId);
    }

    /**
     * Get applicable rates for a vehicle group
     */
    public static function getApplicableRates(
        string $vehicleGroupId,
        bool $activeOnly = true
    ): \Illuminate\Database\Eloquent\Collection {
        $query = static::with(['commonRateDefinition', 'vehicleGroup'])
            ->forVehicleGroup($vehicleGroupId);

        if ($activeOnly) {
            $query->active();
        }

        return $query->orderBy('created_at')->get();
    }

    /**
     * Get pricing matrix for multiple vehicle groups
     */
    public static function getPricingMatrix(
        array $vehicleGroupIds,
        ?string $commonRateId = null
    ): \Illuminate\Database\Eloquent\Collection {
        $query = static::with(['commonRateDefinition', 'vehicleGroup'])
            ->whereIn('vehicle_group_id', $vehicleGroupIds);

        if ($commonRateId) {
            $query->forCommonRate($commonRateId);
        }

        return $query->orderBy('vehicle_group_id')
            // ->orderBy('sort_order')
            ->get();
    }

    /**
     * Bulk update rates for a vehicle group
     */
    public static function bulkUpdateForVehicleGroup(
        string $vehicleGroupId,
        array $rateUpdates
    ): int {
        $updatedCount = 0;

        foreach ($rateUpdates as $update) {
            if (!isset($update['common_rate_definition_id'])) {
                continue;
            }

            $pricing = static::forVehicleGroup($vehicleGroupId)
                ->forCommonRate($update['common_rate_definition_id'])
                ->first();

            if ($pricing) {
                $pricing->update($update);
                $updatedCount++;
            }
        }

        return $updatedCount;
    }

    /**
     * Copy rates from one vehicle group to another
     */
    public static function copyRates(
        string $sourceVehicleGroupId,
        string $targetVehicleGroupId,
        bool $overwriteExisting = false
    ): int {
        $sourceRates = static::forVehicleGroup($sourceVehicleGroupId)->get();
        $copiedCount = 0;

        foreach ($sourceRates as $sourceRate) {
            $existingRate = static::forVehicleGroup($targetVehicleGroupId)
                ->forCommonRate($sourceRate->common_rate_definition_id)
                ->first();

            if ($existingRate && !$overwriteExisting) {
                continue;
            }

            $data = $sourceRate->only([
                'common_rate_definition_id',
                'value',
                'is_enabled',
                'is_mandatory',
                // 'sort_order'
            ]);
            $data['vehicle_group_id'] = $targetVehicleGroupId;

            if ($existingRate) {
                $existingRate->update($data);
            } else {
                static::create($data);
            }

            $copiedCount++;
        }

        return $copiedCount;
    }
}
