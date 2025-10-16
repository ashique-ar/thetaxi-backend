<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Models\ServiceType;
use App\Models\User;
use App\Traits\UUID;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Vehicle Discount Model
 * 
 * Manages discount configurations with precedence-based application:
 * 1. Service + Group-based (highest priority)
 * 2. Service-based only
 * 3. Group-based only  
 * 4. Global (lowest priority)
 */
class VehicleDiscount extends BaseModel
{
    use UUID, SoftDeletes;

    protected $fillable = [
        'code',
        'name', 
        'description',
        'service_type_id',
        'vehicle_group_id',
        'amount',
        'is_percentage',
        'applies_to',
        'valid_from',
        'valid_to',
        'is_active',
        'minimum_amount',
        'maximum_discount',
        'usage_limit',
        'usage_count',
        'created_user_id',
        'updated_user_id'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_percentage' => 'boolean',
        'is_active' => 'boolean',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'minimum_amount' => 'decimal:2',
        'maximum_discount' => 'decimal:2',
        'usage_limit' => 'integer',
        'usage_count' => 'integer'
    ];

    protected $appends = [
        'precedence_level',
        'is_valid',
        'is_available',
        'scope_description'
    ];

    // ===================
    // RELATIONSHIPS
    // ===================

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class, 'vehicle_group_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    // ===================
    // SCOPES
    // ===================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeValid($query)
    {
        $now = Carbon::now();
        return $query->where(function ($q) use ($now) {
            $q->where(function ($sq) use ($now) {
                $sq->whereNull('valid_from')
                   ->orWhere('valid_from', '<=', $now);
            })->where(function ($sq) use ($now) {
                $sq->whereNull('valid_to')
                   ->orWhere('valid_to', '>=', $now);
            });
        });
    }

    public function scopeAvailable($query)
    {
        return $query->active()->valid()->where(function ($q) {
            $q->whereNull('usage_limit')
              ->orWhereRaw('usage_count < usage_limit');
        });
    }

    public function scopeForService($query, $serviceTypeId)
    {
        return $query->where(function ($q) use ($serviceTypeId) {
            $q->whereNull('service_type_id')
              ->orWhere('service_type_id', $serviceTypeId);
        });
    }

    public function scopeForVehicleGroup($query, $vehicleGroupId)
    {
        return $query->where(function ($q) use ($vehicleGroupId) {
            $q->whereNull('vehicle_group_id')
              ->orWhere('vehicle_group_id', $vehicleGroupId);
        });
    }

    public function scopeByPrecedence($query)
    {
        return $query->orderByRaw('
            CASE 
                WHEN service_type_id IS NOT NULL AND vehicle_group_id IS NOT NULL THEN 1
                WHEN service_type_id IS NOT NULL AND vehicle_group_id IS NULL THEN 2  
                WHEN service_type_id IS NULL AND vehicle_group_id IS NOT NULL THEN 3
                ELSE 4
            END
        ')->orderBy('amount', 'desc');
    }

    // ===================
    // ACCESSORS
    // ===================

    public function getPrecedenceLevelAttribute(): int
    {
        if ($this->service_type_id && $this->vehicle_group_id) return 1; // Service + Group
        if ($this->service_type_id && !$this->vehicle_group_id) return 2; // Service only
        if (!$this->service_type_id && $this->vehicle_group_id) return 3; // Group only
        return 4; // Global
    }

    public function getIsValidAttribute(): bool
    {
        $now = Carbon::now();
        
        $validFrom = !$this->valid_from || $this->valid_from <= $now;
        $validTo = !$this->valid_to || $this->valid_to >= $now;
        
        return $validFrom && $validTo;
    }

    public function getIsAvailableAttribute(): bool
    {
        return $this->is_active && 
               $this->is_valid && 
               (!$this->usage_limit || $this->usage_count < $this->usage_limit);
    }

    public function getScopeDescriptionAttribute(): string
    {
        if ($this->service_type_id && $this->vehicle_group_id) {
            return 'Service + Group Specific';
        }
        if ($this->service_type_id && !$this->vehicle_group_id) {
            return 'Service Specific';
        }
        if (!$this->service_type_id && $this->vehicle_group_id) {
            return 'Vehicle Group Specific';
        }
        return 'Global';
    }

    // ===================
    // METHODS
    // ===================

    /**
     * Calculate discount amount for given base amount
     */
    public function calculateDiscount(float $baseAmount): float
    {
        if (!$this->is_available) {
            return 0;
        }

        // Check minimum amount requirement
        if ($this->minimum_amount && $baseAmount < $this->minimum_amount) {
            return 0;
        }

        $discountAmount = $this->is_percentage 
            ? ($baseAmount * $this->amount / 100)
            : $this->amount;

        // Apply maximum discount cap
        if ($this->maximum_discount && $discountAmount > $this->maximum_discount) {
            $discountAmount = $this->maximum_discount;
        }

        return round($discountAmount, 2);
    }

    /**
     * Check if discount is applicable to specific criteria
     */
    public function isApplicable(
        ?string $serviceTypeId = null, 
        ?string $vehicleGroupId = null, 
        ?float $amount = null
    ): bool {
        // Check if discount is available
        if (!$this->is_available) {
            return false;
        }

        // Check service type match
        if ($this->service_type_id && $serviceTypeId !== $this->service_type_id) {
            return false;
        }

        // Check vehicle group match  
        if ($this->vehicle_group_id && $vehicleGroupId !== $this->vehicle_group_id) {
            return false;
        }

        // Check minimum amount requirement
        if ($amount && $this->minimum_amount && $amount < $this->minimum_amount) {
            return false;
        }

        return true;
    }

    /**
     * Increment usage count
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Get all applicable discounts for specific criteria
     */
    public static function getApplicableDiscounts(
        ?string $serviceTypeId = null,
        ?string $vehicleGroupId = null,
        ?float $amount = null
    ) {
        return static::available()
            ->forService($serviceTypeId)
            ->forVehicleGroup($vehicleGroupId)
            ->when($amount, function ($query) use ($amount) {
                $query->where(function ($q) use ($amount) {
                    $q->whereNull('minimum_amount')
                      ->orWhere('minimum_amount', '<=', $amount);
                });
            })
            ->byPrecedence()
            ->get()
            ->filter(function ($discount) use ($serviceTypeId, $vehicleGroupId, $amount) {
                return $discount->isApplicable($serviceTypeId, $vehicleGroupId, $amount);
            });
    }

    /**
     * Get the best applicable discount
     */
    public static function getBestDiscount(
        ?string $serviceTypeId = null,
        ?string $vehicleGroupId = null,
        ?float $amount = null
    ): ?self {
        return static::getApplicableDiscounts($serviceTypeId, $vehicleGroupId, $amount)->first();
    }
}
