<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Models\Service\ServiceType;
use App\Models\User;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vehicle Pricing History Model
 * 
 * Tracks all pricing changes over time with detailed information
 * about what changed, when, and who made the changes
 */
class VehiclePricingHistory extends BaseModel
{
    use UUID, SoftDeletes;

    protected $table = 'vehicle_pricing_history';

    protected $fillable = [
        'vehicle_group_id',
        'service_type_id',
        'record_type',
        'pricing_slab_definition_id',
        'common_rate_definition_id',
        'old_rate',
        'new_rate',
        'rate_change',
        'percentage_change',
        'change_type',
        'change_reason',
        'old_pricing_data',
        'new_pricing_data',
        'changed_by',
        'changed_at'
    ];

    protected $casts = [
        'old_rate' => 'decimal:2',
        'new_rate' => 'decimal:2',
        'rate_change' => 'decimal:2',
        'percentage_change' => 'decimal:2',
        'old_pricing_data' => 'json',
        'new_pricing_data' => 'json',
        'changed_at' => 'datetime'
    ];

    /**
     * Get the vehicle group associated with this pricing history
     */
    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class, 'vehicle_group_id');
    }

    /**
     * Get the service type associated with this pricing history
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    /**
     * Get the pricing slab definition associated with this pricing history
     */
    public function slabDefinition(): BelongsTo
    {
        return $this->belongsTo(VehiclePricingSlabDefinition::class, 'pricing_slab_definition_id');
    }
    /**
     * Get the common rate definition associated with this pricing history
     */
    public function commonRateDefinition(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition::class, 'common_rate_definition_id');
    }

    /**
     * Get the user who made the change
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Scope to filter by date range
     */
    public function scopeByDateRange($query, $fromDate, $toDate)
    {
        return $query->whereBetween('changed_at', [$fromDate, $toDate]);
    }

    /**
     * Scope to filter by change type
     */
    public function scopeByChangeType($query, $changeType)
    {
        return $query->where('change_type', $changeType);
    }

    /**
     * Scope to filter by vehicle group
     */
    public function scopeByVehicleGroup($query, $vehicleGroupId)
    {
        return $query->where('vehicle_group_id', $vehicleGroupId);
    }

    /**
     * Scope to filter by service type
     */
    public function scopeByServiceType($query, $serviceTypeId)
    {
        return $query->where('service_type_id', $serviceTypeId);
    }

    /**
     * Scope to filter by user who made changes
     */
    public function scopeByChangedBy($query, $userId)
    {
        return $query->where('changed_by', $userId);
    }

    /**
     * Get formatted rate change with sign
     */
    public function getFormattedRateChangeAttribute()
    {
        $sign = $this->rate_change >= 0 ? '+' : '';
        $amount = (float) $this->rate_change;
        return $sign . number_format($amount < 0 ? ceil($amount) : floor($amount), 0);
    }

    /**
     * Get formatted percentage change with sign
     */
    public function getFormattedPercentageChangeAttribute()
    {
        $sign = $this->percentage_change >= 0 ? '+' : '';
        return $sign . number_format($this->percentage_change, 2) . '%';
    }

    /**
     * Get change type label
     */
    public function getChangeTypeLabelAttribute()
    {
        return match($this->change_type) {
            'increase' => 'Price Increase',
            'decrease' => 'Price Decrease',
            'no_change' => 'No Change',
            default => 'Unknown'
        };
    }

    /**
     * Create a pricing history record for both slab and common rate pricing
     */
    public static function createHistoryRecord(array $data)
    {
        // Calculate rate change and percentage
        $rateChange = $data['new_rate'] - $data['old_rate'];
        $percentageChange = $data['old_rate'] > 0 ? ($rateChange / $data['old_rate']) * 100 : 0;
        
        // Determine change type
        $changeType = 'no_change';
        if ($rateChange > 0) {
            $changeType = 'increase';
        } elseif ($rateChange < 0) {
            $changeType = 'decrease';
        }

        return self::create([
            'vehicle_group_id' => $data['vehicle_group_id'],
            'service_type_id' => $data['service_type_id'],
            'record_type' => $data['record_type'] ?? 'slab_pricing',
            'pricing_slab_definition_id' => $data['pricing_slab_definition_id'] ?? null,
            'common_rate_definition_id' => $data['common_rate_definition_id'] ?? null,
            'old_rate' => $data['old_rate'],
            'new_rate' => $data['new_rate'],
            'rate_change' => $rateChange,
            'percentage_change' => $percentageChange,
            'change_type' => $changeType,
            'change_reason' => $data['change_reason'] ?? null,
            'old_pricing_data' => $data['old_pricing_data'] ?? null,
            'new_pricing_data' => $data['new_pricing_data'] ?? null,
            'changed_by' => $data['changed_by'],
            'changed_at' => $data['changed_at'] ?? now()
        ]);
    }
}
