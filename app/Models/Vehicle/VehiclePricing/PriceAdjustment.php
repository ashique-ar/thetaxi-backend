<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Models\ServiceType;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Booking\Booking;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PriceAdjustment extends BaseModel
{
    protected $table = 'price_adjustments';
    
    protected $fillable = [
        'name',
        'description',
        'scope',
        'service_type_id',
        'vehicle_group_id',
        'adjustment_type',
        'percentage_change',
        'fixed_amount_change',
        'applies_to',
        'minimum_booking_amount',
        'maximum_discount_amount',
        'is_active',
        'priority',
        'is_cumulative',
        'valid_from',
        'valid_to',
        'usage_limit',
        'usage_count',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'percentage_change' => 'decimal:4',
        'fixed_amount_change' => 'decimal:2',
        'minimum_booking_amount' => 'decimal:2',
        'maximum_discount_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'is_cumulative' => 'boolean',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'usage_limit' => 'integer',
        'usage_count' => 'integer',
    ];

    /**
     * Relationships
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class);
    }

    public function bookingAdjustmentHistory(): HasMany
    {
        return $this->hasMany(BookingPriceAdjustmentHistory::class);
    }

    /**
     * Scopes
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeValid(Builder $query, Carbon $date = null): Builder
    {
        $date = $date ?? now();
        
        return $query->where('valid_from', '<=', $date)
                     ->where('valid_to', '>=', $date);
    }

    public function scopeWithinUsageLimit(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('usage_limit')
              ->orWhereRaw('usage_count < usage_limit');
        });
    }

    public function scopeForScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    public function scopeForService(Builder $query, string $serviceTypeId): Builder
    {
        return $query->where(function (Builder $q) use ($serviceTypeId) {
            $q->where('scope', 'global')
              ->orWhere(function (Builder $q2) use ($serviceTypeId) {
                  $q2->where('scope', 'service')
                     ->where('service_type_id', $serviceTypeId);
              });
        });
    }

    public function scopeForVehicleGroup(Builder $query, string $vehicleGroupId): Builder
    {
        return $query->where(function (Builder $q) use ($vehicleGroupId) {
            $q->where('scope', 'global')
              ->orWhere(function (Builder $q2) use ($vehicleGroupId) {
                  $q2->where('scope', 'vehicle_group')
                     ->where('vehicle_group_id', $vehicleGroupId);
              });
        });
    }

    public function scopeForAmount(Builder $query, float $amount): Builder
    {
        return $query->where(function (Builder $q) use ($amount) {
            $q->whereNull('minimum_booking_amount')
              ->orWhere('minimum_booking_amount', '<=', $amount);
        });
    }

    public function scopeOrderByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority', 'desc')->orderBy('created_at', 'asc');
    }

    public function scopeCumulative(Builder $query): Builder
    {
        return $query->where('is_cumulative', true);
    }

    public function scopeNonCumulative(Builder $query): Builder
    {
        return $query->where('is_cumulative', false);
    }

    /**
     * Business Logic Methods
     */

    /**
     * Check if this adjustment is currently valid
     */
    public function isValid(Carbon $date = null): bool
    {
        $date = $date ?? now();
        
        $withinValidPeriod = $date->gte($this->valid_from) && $date->lte($this->valid_to);
        $withinUsageLimit = $this->usage_limit === null || $this->usage_count < $this->usage_limit;
        
        return $this->is_active && $withinValidPeriod && $withinUsageLimit;
    }

    /**
     * Check if this adjustment applies to given booking amount
     */
    public function appliesTo(float $bookingAmount): bool
    {
        if ($this->minimum_booking_amount !== null && $bookingAmount < $this->minimum_booking_amount) {
            return false;
        }
        
        return $this->isValid();
    }

    /**
     * Calculate adjustment amount
     */
    public function calculateAdjustment(float $amount, string $priceComponent = null): array
    {
        if (!$this->appliesTo($amount)) {
            return [
                'applicable' => false,
                'adjustment_amount' => 0,
                'final_amount' => $amount,
                'calculation_details' => 'Adjustment does not apply to this booking',
            ];
        }

        $adjustmentAmount = 0;
        $calculationDetails = [];

        switch ($this->adjustment_type) {
            case 'percentage':
                $adjustmentAmount = $amount * $this->percentage_change;
                
                // Apply maximum discount limit for negative adjustments
                if ($adjustmentAmount < 0 && $this->maximum_discount_amount !== null) {
                    $maxDiscount = -abs($this->maximum_discount_amount);
                    $adjustmentAmount = max($adjustmentAmount, $maxDiscount);
                }
                
                $calculationDetails = [
                    'type' => 'percentage',
                    'base_amount' => $amount,
                    'percentage_change' => $this->percentage_change,
                    'percentage_display' => ($this->percentage_change * 100) . '%',
                    'calculation' => "{$amount} × {$this->percentage_change} = {$adjustmentAmount}",
                    'max_discount_applied' => $this->maximum_discount_amount !== null && $adjustmentAmount < 0,
                ];
                break;

            case 'fixed_amount':
                $adjustmentAmount = $this->fixed_amount_change;
                
                // Apply maximum discount limit for negative adjustments
                if ($adjustmentAmount < 0 && $this->maximum_discount_amount !== null) {
                    $maxDiscount = -abs($this->maximum_discount_amount);
                    $adjustmentAmount = max($adjustmentAmount, $maxDiscount);
                }
                
                $calculationDetails = [
                    'type' => 'fixed_amount',
                    'fixed_amount_change' => $this->fixed_amount_change,
                    'calculation' => "Fixed adjustment: {$adjustmentAmount}",
                    'max_discount_applied' => $this->maximum_discount_amount !== null && $adjustmentAmount < 0,
                ];
                break;

            default:
                Log::warning("Unknown adjustment_type for price adjustment: {$this->adjustment_type}", ['adjustment_id' => $this->id]);
                break;
        }

        return [
            'applicable' => true,
            'adjustment_amount' => $adjustmentAmount,
            'final_amount' => $amount + $adjustmentAmount,
            'calculation_details' => $calculationDetails,
            'adjustment_info' => [
                'id' => $this->id,
                'name' => $this->name,
                'scope' => $this->scope,
                'applies_to' => $this->applies_to,
                'priority' => $this->priority,
                'is_cumulative' => $this->is_cumulative,
            ],
        ];
    }

    /**
     * Increment usage count
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Get applicable adjustments for given parameters
     */
    public static function getApplicableAdjustments(
        float $amount,
        ?string $serviceTypeId = null,
        ?string $vehicleGroupId = null,
        string $priceComponent = 'total_price',
        Carbon $date = null
    ): \Illuminate\Database\Eloquent\Collection {
        $query = static::active()
            ->valid($date)
            ->withinUsageLimit()
            ->forAmount($amount)
            ->where('applies_to', $priceComponent);

        // Apply scope filtering
        if ($serviceTypeId && $vehicleGroupId) {
            // Both service and vehicle group specified
            $query->where(function (Builder $q) use ($serviceTypeId, $vehicleGroupId) {
                $q->where('scope', 'global')
                  ->orWhere(function (Builder $q2) use ($serviceTypeId) {
                      $q2->where('scope', 'service')->where('service_type_id', $serviceTypeId);
                  })
                  ->orWhere(function (Builder $q3) use ($vehicleGroupId) {
                      $q3->where('scope', 'vehicle_group')->where('vehicle_group_id', $vehicleGroupId);
                  });
            });
        } elseif ($serviceTypeId) {
            $query->forService($serviceTypeId);
        } elseif ($vehicleGroupId) {
            $query->forVehicleGroup($vehicleGroupId);
        } else {
            $query->forScope('global');
        }

        return $query->orderByPriority()->get();
    }

    /**
     * Apply multiple adjustments with cumulative logic
     */
    public static function applyAdjustments(
        float $amount,
        ?string $serviceTypeId = null,
        ?string $vehicleGroupId = null,
        string $priceComponent = 'total_price',
        Carbon $date = null
    ): array {
        $adjustments = static::getApplicableAdjustments(
            $amount, 
            $serviceTypeId, 
            $vehicleGroupId, 
            $priceComponent, 
            $date
        );

        if ($adjustments->isEmpty()) {
            return [
                'adjustments_applied' => [],
                'total_adjustment' => 0,
                'final_amount' => $amount,
                'calculation_summary' => 'No applicable price adjustments found',
            ];
        }

        $appliedAdjustments = [];
        $totalAdjustment = 0;
        $currentAmount = $amount;

        // Separate cumulative and non-cumulative adjustments
        $cumulativeAdjustments = $adjustments->where('is_cumulative', true);
        $nonCumulativeAdjustments = $adjustments->where('is_cumulative', false);

        // Apply the best non-cumulative adjustment first (highest priority)
        if ($nonCumulativeAdjustments->isNotEmpty()) {
            $bestAdjustment = $nonCumulativeAdjustments->first();
            $result = $bestAdjustment->calculateAdjustment($currentAmount, $priceComponent);
            
            if ($result['applicable']) {
                $appliedAdjustments[] = $result;
                $totalAdjustment += $result['adjustment_amount'];
                $currentAmount = $result['final_amount'];
            }
        }

        // Apply cumulative adjustments
        foreach ($cumulativeAdjustments as $adjustment) {
            $result = $adjustment->calculateAdjustment($currentAmount, $priceComponent);
            
            if ($result['applicable']) {
                $appliedAdjustments[] = $result;
                $totalAdjustment += $result['adjustment_amount'];
                $currentAmount = $result['final_amount'];
            }
        }

        return [
            'adjustments_applied' => $appliedAdjustments,
            'total_adjustment' => $totalAdjustment,
            'final_amount' => $amount + $totalAdjustment,
            'calculation_summary' => count($appliedAdjustments) > 0 
                ? "Applied " . count($appliedAdjustments) . " price adjustment(s)"
                : "No price adjustments applied",
        ];
    }

    /**
     * Validation rules
     */
    public static function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'scope' => 'required|in:global,service,vehicle_group',
            'service_type_id' => 'nullable|uuid|exists:service_types,id|required_if:scope,service',
            'vehicle_group_id' => 'nullable|uuid|exists:vehicle_groups,id|required_if:scope,vehicle_group',
            'adjustment_type' => 'required|in:percentage,fixed_amount',
            'percentage_change' => 'nullable|numeric|required_if:adjustment_type,percentage',
            'fixed_amount_change' => 'nullable|numeric|required_if:adjustment_type,fixed_amount',
            'applies_to' => 'required|in:base_price,total_price,km_charges',
            'minimum_booking_amount' => 'nullable|numeric|min:0',
            'maximum_discount_amount' => 'nullable|numeric|min:0',
            'priority' => 'integer|min:0',
            'is_cumulative' => 'boolean',
            'valid_from' => 'required|date',
            'valid_to' => 'required|date|after:valid_from',
            'usage_limit' => 'nullable|integer|min:1',
        ];
    }
}