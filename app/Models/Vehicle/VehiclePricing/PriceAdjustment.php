<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Models\Service\ServiceType;
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

    public function scopeValid(Builder $query, Carbon $startDate = null, Carbon $endDate = null): Builder
    {
        $startDate = $startDate ?? now();
        $endDate = $endDate ?? $startDate;

        return $query->where('valid_from', '<=', $endDate)
            ->where('valid_to', '>=', $startDate);
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
            $q->whereNull('service_type_id')
                ->orWhere('service_type_id', $serviceTypeId);
        });
    }

    public function scopeForVehicleGroup(Builder $query, string $vehicleGroupId): Builder
    {
        return $query->where(function (Builder $q) use ($vehicleGroupId) {
            $q->whereNull('vehicle_group_id')
                ->orWhere('vehicle_group_id', $vehicleGroupId);
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
        return $query
            ->orderByRaw("
                CASE
                    WHEN service_type_id IS NOT NULL AND vehicle_group_id IS NOT NULL THEN 1
                    WHEN service_type_id IS NOT NULL AND vehicle_group_id IS NULL THEN 2
                    WHEN service_type_id IS NULL AND vehicle_group_id IS NOT NULL THEN 3
                    ELSE 4
                END ASC
            ")
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc');
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
    public function isValid(Carbon $startDate = null, Carbon $endDate = null): bool
    {
        $startDate = $startDate ?? now();
        $endDate = $endDate ?? $startDate;

        $withinValidPeriod = $startDate->lte($this->valid_to) && $endDate->gte($this->valid_from);
        $withinUsageLimit = $this->usage_limit === null || $this->usage_count < $this->usage_limit;

        return $this->is_active && $withinValidPeriod && $withinUsageLimit;
    }

    /**
     * Check if this adjustment applies to given booking amount
     */
    public function appliesTo(float $bookingAmount, Carbon $startDate = null, Carbon $endDate = null): bool
    {
        if ($this->minimum_booking_amount !== null && $bookingAmount < $this->minimum_booking_amount) {
            return false;
        }

        return $this->isValid($startDate, $endDate);
    }

    /**
     * Calculate adjustment amount
     * 
     * Handles both percentage and fixed amount adjustments.
     * For percentage: User enters value like -10 for 10% discount or 15 for 15% increase.
     * For fixed_amount: User enters value like -500 for LKR 500 discount or 200 for LKR 200 increase.
     * Negative values = discounts, Positive values = increases
     */
    public function calculateAdjustment(
        float $amount,
        string $priceComponent = null,
        Carbon $startDate = null,
        Carbon $endDate = null
    ): array
    {
        if (!$this->appliesTo($amount, $startDate, $endDate)) {
            return [
                'applicable' => false,
                'adjustment_amount' => 0,
                'final_amount' => $amount,
                'original_amount' => $amount,
                'is_discount' => false,
                'calculation_details' => 'Adjustment does not apply to this booking',
            ];
        }

        $adjustmentAmount = 0;
        $calculationDetails = [];

        switch ($this->adjustment_type) {
            case 'percentage':
                // User enters percentage as whole number (e.g., -10 for 10% discount, 15 for 15% increase)
                // Convert to decimal for calculation: -10 becomes -0.10
                $percentageDecimal = (float) $this->percentage_change / 100;
                $adjustmentAmount = round($amount * $percentageDecimal, 2);

                // Apply maximum discount limit for negative adjustments (discounts)
                if ($adjustmentAmount < 0 && $this->maximum_discount_amount !== null) {
                    $maxDiscount = -abs($this->maximum_discount_amount);
                    $adjustmentAmount = max($adjustmentAmount, $maxDiscount);
                }

                $calculationDetails = [
                    'type' => 'percentage',
                    'base_amount' => $amount,
                    'percentage_value' => (float) $this->percentage_change,
                    'percentage_decimal' => $percentageDecimal,
                    'percentage_display' => ($this->percentage_change >= 0 ? '+' : '') . $this->percentage_change . '%',
                    'calculation' => "{$amount} × {$percentageDecimal} = {$adjustmentAmount}",
                    'max_discount_applied' => $this->maximum_discount_amount !== null && $adjustmentAmount < 0,
                    'max_discount_cap' => $this->maximum_discount_amount,
                ];
                break;

            case 'fixed_amount':
                // User enters fixed amount directly (e.g., -500 for LKR 500 discount, 200 for LKR 200 increase)
                $adjustmentAmount = round((float) $this->fixed_amount_change, 2);

                // Apply maximum discount limit for negative adjustments (discounts)
                if ($adjustmentAmount < 0 && $this->maximum_discount_amount !== null) {
                    $maxDiscount = -abs($this->maximum_discount_amount);
                    $adjustmentAmount = max($adjustmentAmount, $maxDiscount);
                }

                $calculationDetails = [
                    'type' => 'fixed_amount',
                    'fixed_amount_value' => (float) $this->fixed_amount_change,
                    'adjustment_display' => ($this->fixed_amount_change >= 0 ? '+' : '') . 'LKR ' . abs($this->fixed_amount_change),
                    'calculation' => "Fixed adjustment: {$adjustmentAmount}",
                    'max_discount_applied' => $this->maximum_discount_amount !== null && $adjustmentAmount < 0,
                    'max_discount_cap' => $this->maximum_discount_amount,
                ];
                break;

            default:
                Log::warning("Unknown adjustment_type for price adjustment: {$this->adjustment_type}", ['adjustment_id' => $this->id]);
                break;
        }

        $isDiscount = $adjustmentAmount < 0;
        $finalAmount = max(0, $amount + $adjustmentAmount); // Ensure final amount doesn't go negative

        return [
            'applicable' => true,
            'adjustment_amount' => $adjustmentAmount,
            'original_amount' => $amount,
            'final_amount' => $finalAmount,
            'is_discount' => $isDiscount,
            'discount_amount' => $isDiscount ? abs($adjustmentAmount) : 0,
            'savings_display' => $isDiscount ? 'LKR ' . number_format(abs($adjustmentAmount), 2) : null,
            'calculation_details' => $calculationDetails,
            'adjustment_info' => [
                'id' => $this->id,
                'name' => $this->name,
                'description' => $this->description,
                'scope' => $this->scope,
                'applies_to' => $this->applies_to,
                'adjustment_type' => $this->adjustment_type,
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
        Carbon $date = null,
        Carbon $endDate = null
    ): \Illuminate\Database\Eloquent\Collection {
        $query = static::active()
            ->valid($date, $endDate)
            ->withinUsageLimit()
            ->forAmount($amount)
            ->where('applies_to', $priceComponent);

        if ($serviceTypeId) {
            $query->forService($serviceTypeId);
        } else {
            $query->whereNull('service_type_id');
        }

        if ($vehicleGroupId) {
            $query->forVehicleGroup($vehicleGroupId);
        } else {
            $query->whereNull('vehicle_group_id');
        }

        return $query->orderByPriority()->get();
    }

    /**
     * Apply multiple adjustments with cumulative logic
     * 
     * Applies all applicable price adjustments to the given amount.
     * Non-cumulative adjustments: Only the highest priority one applies
     * Cumulative adjustments: Stack on top of each other
     * 
     * @param float $amount The base amount to apply adjustments to
     * @param string|null $serviceTypeId Service type for filtering
     * @param string|null $vehicleGroupId Vehicle group for filtering
     * @param string $priceComponent Which price component to apply to (base_price, total_price, km_charges)
     * @param Carbon|null $date Date for validity check
     * @return array Results including all adjustments applied and final amount
     */
    public static function applyAdjustments(
        float $amount,
        ?string $serviceTypeId = null,
        ?string $vehicleGroupId = null,
        string $priceComponent = 'total_price',
        Carbon $date = null,
        Carbon $endDate = null
    ): array {
        $originalAmount = $amount;

        $adjustments = static::getApplicableAdjustments(
            $amount,
            $serviceTypeId,
            $vehicleGroupId,
            $priceComponent,
            $date,
            $endDate
        );

        if ($adjustments->isEmpty()) {
            return [
                'adjustments_applied' => [],
                'total_adjustment' => 0,
                'total_discount' => 0,
                'total_increase' => 0,
                'original_amount' => $originalAmount,
                'final_amount' => $amount,
                'has_discount' => false,
                'has_increase' => false,
                'savings_display' => null,
                'calculation_summary' => 'No applicable price adjustments found',
            ];
        }

        $appliedAdjustments = [];
        $totalAdjustment = 0;
        $totalDiscount = 0;
        $totalIncrease = 0;
        $currentAmount = $amount;

        // Separate cumulative and non-cumulative adjustments
        $cumulativeAdjustments = $adjustments->where('is_cumulative', true);
        $nonCumulativeAdjustments = $adjustments->where('is_cumulative', false);

        // Apply the best non-cumulative adjustment first (highest priority)
        if ($nonCumulativeAdjustments->isNotEmpty()) {
            $bestAdjustment = $nonCumulativeAdjustments->first();
            $result = $bestAdjustment->calculateAdjustment($currentAmount, $priceComponent, $date, $endDate);

            if ($result['applicable']) {
                $appliedAdjustments[] = $result;
                $totalAdjustment += $result['adjustment_amount'];
                $currentAmount = $result['final_amount'];

                if ($result['is_discount']) {
                    $totalDiscount += abs($result['adjustment_amount']);
                } else {
                    $totalIncrease += $result['adjustment_amount'];
                }
            }
        }

        // Apply cumulative adjustments
        foreach ($cumulativeAdjustments as $adjustment) {
            $result = $adjustment->calculateAdjustment($currentAmount, $priceComponent, $date, $endDate);

            if ($result['applicable']) {
                $appliedAdjustments[] = $result;
                $totalAdjustment += $result['adjustment_amount'];
                $currentAmount = $result['final_amount'];

                if ($result['is_discount']) {
                    $totalDiscount += abs($result['adjustment_amount']);
                } else {
                    $totalIncrease += $result['adjustment_amount'];
                }
            }
        }

        $hasDiscount = $totalDiscount > 0;
        $hasIncrease = $totalIncrease > 0;
        $finalAmount = max(0, $originalAmount + $totalAdjustment);

        return [
            'adjustments_applied' => $appliedAdjustments,
            'total_adjustment' => round($totalAdjustment, 2),
            'total_discount' => round($totalDiscount, 2),
            'total_increase' => round($totalIncrease, 2),
            'original_amount' => $originalAmount,
            'final_amount' => round($finalAmount, 2),
            'has_discount' => $hasDiscount,
            'has_increase' => $hasIncrease,
            'savings_display' => $hasDiscount ? 'LKR ' . number_format($totalDiscount, 2) : null,
            'discount_percentage' => $hasDiscount && $originalAmount > 0
                ? round(($totalDiscount / $originalAmount) * 100, 1)
                : 0,
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
            'applies_to' => 'nullable|in:base_price,total_price,km_charges',
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
