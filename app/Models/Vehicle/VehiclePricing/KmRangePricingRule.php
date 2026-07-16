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

class KmRangePricingRule extends BaseModel
{
    protected $table = 'km_range_pricing_rules';
    
    protected $fillable = [
        'name',
        'description',
        'scope',
        'service_type_id',
        'vehicle_group_id',
        'owner_type',
        'owner_id',
        'from_km',
        'to_km',
        'price_type',
        'rate_per_km',
        'percentage',
        'flat_amount',
        'is_active',
        'priority',
        'effective_from',
        'effective_to',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'from_km' => 'decimal:2',
        'to_km' => 'decimal:2',
        'rate_per_km' => 'decimal:4',
        'percentage' => 'decimal:4',
        'flat_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
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

    public function scopeEffective(Builder $query, Carbon $date = null): Builder
    {
        $date = $date ?? now();
        
        return $query->where(function (Builder $q) use ($date) {
            $q->where('effective_from', '<=', $date)
              ->where(function (Builder $q2) use ($date) {
                  $q2->whereNull('effective_to')
                     ->orWhere('effective_to', '>=', $date);
              });
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

    public function scopeForDistance(Builder $query, float $distance): Builder
    {
        return $query->where('from_km', '<=', $distance)
                     ->where(function (Builder $q) use ($distance) {
                         $q->whereNull('to_km')
                           ->orWhere('to_km', '>=', $distance);
                     });
    }

    public function scopeOrderByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority', 'desc')->orderBy('created_at', 'asc');
    }

    /**
     * Business Logic Methods
     */

    /**
     * Check if this rule applies to the given distance
     */
    public function appliesTo(float $distance): bool
    {
        $withinRange = $distance >= $this->from_km;
        
        if ($this->to_km !== null) {
            $withinRange = $withinRange && $distance <= $this->to_km;
        }
        
        return $withinRange && $this->isEffective();
    }

    /**
     * Check if this rule is currently effective
     */
    public function isEffective(Carbon $date = null): bool
    {
        $date = $date ?? now();
        
        $effectiveFrom = $this->effective_from === null || $date->gte($this->effective_from);
        $effectiveTo = $this->effective_to === null || $date->lte($this->effective_to);
        
        return $this->is_active && $effectiveFrom && $effectiveTo;
    }

    /**
     * Calculate the pricing for given distance
     */
    public function calculatePricing(float $distance, float $baseAmount = 0): array
    {
        if (!$this->appliesTo($distance)) {
            return [
                'applicable' => false,
                'adjustment_amount' => 0,
                'final_amount' => $baseAmount,
                'calculation_details' => 'Rule does not apply to this distance',
            ];
        }

        $adjustmentAmount = 0;
        $calculationDetails = [];

        switch ($this->price_type) {
            case 'fixed_rate':
                $adjustmentAmount = $distance * $this->rate_per_km;
                $calculationDetails = [
                    'type' => 'fixed_rate',
                    'distance' => $distance,
                    'rate_per_km' => $this->rate_per_km,
                    'calculation' => "{$distance} km × {$this->rate_per_km} = {$adjustmentAmount}",
                ];
                break;

            case 'percentage_multiplier':
                if (!is_numeric($this->percentage) || (float) $this->percentage < 0 || (float) $this->percentage > 10) {
                    throw new \DomainException(
                        'KM range price multiplier must be between 0 and 10 (for example 1.10 means a 10% increase).'
                    );
                }
                $adjustmentAmount = $baseAmount * ($this->percentage - 1);
                $calculationDetails = [
                    'type' => 'percentage_multiplier',
                    'base_amount' => $baseAmount,
                    'multiplier' => $this->percentage,
                    'percentage_change' => ($this->percentage - 1) * 100,
                    'calculation' => "{$baseAmount} × {$this->percentage} - {$baseAmount} = {$adjustmentAmount}",
                ];
                break;

            case 'flat_addition':
                $adjustmentAmount = $this->flat_amount;
                $calculationDetails = [
                    'type' => 'flat_addition',
                    'flat_amount' => $this->flat_amount,
                    'calculation' => "Flat addition: {$this->flat_amount}",
                ];
                break;

            default:
                Log::warning("Unknown price_type for KM range rule: {$this->price_type}", ['rule_id' => $this->id]);
                break;
        }

        return [
            'applicable' => true,
            'adjustment_amount' => $adjustmentAmount,
            'final_amount' => $baseAmount + $adjustmentAmount,
            'calculation_details' => $calculationDetails,
            'rule_info' => [
                'id' => $this->id,
                'name' => $this->name,
                'scope' => $this->scope,
                'km_range' => "{$this->from_km} - " . ($this->to_km ?? '∞') . " km",
                'priority' => $this->priority,
            ],
        ];
    }

    /**
     * Get applicable rules for given parameters
     */
    public static function getApplicableRules(
        float $distance,
        ?string $serviceTypeId = null,
        ?string $vehicleGroupId = null,
        Carbon $date = null,
        ?string $ownerType = null,
        ?string $ownerId = null
    ): \Illuminate\Database\Eloquent\Collection {
        $query = static::active()->effective($date)->forDistance($distance);
        static::applyOwnerScope($query, $ownerType, $ownerId);

        // Apply scope filtering
        if ($serviceTypeId && $vehicleGroupId) {
            // Both service and vehicle group specified - get all applicable rules
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
            // Only service specified
            $query->forService($serviceTypeId);
        } elseif ($vehicleGroupId) {
            // Only vehicle group specified
            $query->forVehicleGroup($vehicleGroupId);
        } else {
            // No specific scope - only global rules
            $query->forScope('global');
        }

        return $query->orderByPriority()
            ->get()
            ->sort(function (self $left, self $right) use ($ownerType, $ownerId): int {
                $rank = static function (self $rule) use ($ownerType, $ownerId): array {
                    $ownerExact = $ownerType && $ownerId
                        && $rule->owner_type === $ownerType
                        && (string) $rule->owner_id === (string) $ownerId;
                    $scopeRank = match ($rule->scope) {
                        'vehicle_group' => 0,
                        'service' => 1,
                        default => 2,
                    };
                    $rangeWidth = $rule->to_km === null
                        ? PHP_FLOAT_MAX
                        : max(0.0, (float) $rule->to_km - (float) $rule->from_km);

                    return [
                        $ownerExact ? 0 : 1,
                        -((int) $rule->priority),
                        $scopeRank,
                        $rangeWidth,
                        (string) $rule->created_at,
                        (string) $rule->id,
                    ];
                };

                return $rank($left) <=> $rank($right);
            })
            ->values();
    }

    /**
     * Calculate best pricing from multiple applicable rules
     */
    public static function calculateBestPricing(
        float $distance,
        float $baseAmount = 0,
        ?string $serviceTypeId = null,
        ?string $vehicleGroupId = null,
        Carbon $date = null,
        ?string $ownerType = null,
        ?string $ownerId = null
    ): array {
        $applicableRules = static::getApplicableRules($distance, $serviceTypeId, $vehicleGroupId, $date, $ownerType, $ownerId);
        
        if ($applicableRules->isEmpty()) {
            return [
                'rules_applied' => [],
                'total_adjustment' => 0,
                'final_amount' => $baseAmount,
                'calculation_summary' => 'No applicable KM-range pricing rules found',
            ];
        }

        // Configuration order is authoritative. Choosing the cheapest rule
        // here made the advertised priority field ineffective and could select
        // a public fallback over a corporate rule.
        $bestResult = $applicableRules->first()->calculatePricing($distance, $baseAmount);
        $appliedRules = ($bestResult['applicable'] ?? false) ? [$bestResult] : [];
        $totalAdjustment = (float) ($bestResult['adjustment_amount'] ?? 0);

        return [
            'rules_applied' => $appliedRules,
            'total_adjustment' => $totalAdjustment,
            'final_amount' => $baseAmount + $totalAdjustment,
            'calculation_summary' => count($appliedRules) > 0 
                ? "Applied " . count($appliedRules) . " KM-range pricing rule(s)"
                : "No KM-range pricing adjustments applied",
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
            'owner_type' => 'nullable|string|in:corporate',
            'owner_id' => 'nullable|uuid|required_with:owner_type',
            'from_km' => 'required|numeric|min:0',
            'to_km' => 'nullable|numeric|min:0|gt:from_km',
            'price_type' => 'required|in:fixed_rate,percentage_multiplier,flat_addition',
            'rate_per_km' => 'nullable|numeric|min:0|required_if:price_type,fixed_rate',
            'percentage' => 'nullable|numeric|between:0,10|required_if:price_type,percentage_multiplier',
            'flat_amount' => 'nullable|numeric|required_if:price_type,flat_addition',
            'priority' => 'integer|min:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after:effective_from',
        ];
    }

    private static function applyOwnerScope(Builder $query, ?string $ownerType, ?string $ownerId): void
    {
        if ($ownerType && $ownerId) {
            $query->where(function (Builder $q) use ($ownerType, $ownerId) {
                $q->where(function (Builder $scoped) use ($ownerType, $ownerId) {
                    $scoped->where('owner_type', $ownerType)->where('owner_id', $ownerId);
                })->orWhereNull('owner_type');
            });

            return;
        }

        $query->whereNull('owner_type')->whereNull('owner_id');
    }
}
