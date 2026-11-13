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
    public const APPLICABLE_CONTEXTS = ['portal', 'public', 'corporate'];
    public const DEFAULT_APPLICABLE_CONTEXTS = ['public'];

    protected $table = 'km_range_pricing_rules';
    
    protected $fillable = [
        'name',
        'description',
        'scope',
        'service_type_id',
        'vehicle_group_id',
        'distance_types',
        'applicable_contexts',
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
        'distance_types' => 'array',
        'applicable_contexts' => 'array',
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

    public function scopeEffective(Builder $query, ?Carbon $date = null): Builder
    {
        $date = $date ?? now();
        
        return $query->where(function (Builder $q) use ($date) {
            $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
        })->where(function (Builder $q) use ($date) {
            $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
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

    public function scopeForDistanceType(Builder $query, string $distanceType): Builder
    {
        return $query->whereJsonContains('distance_types', $distanceType);
    }

    public function scopeForPricingContext(Builder $query, ?string $pricingContext): Builder
    {
        $context = in_array($pricingContext, self::APPLICABLE_CONTEXTS, true)
            ? $pricingContext
            : 'public';

        return $query->where(function (Builder $q) use ($context) {
            $q->whereJsonContains('applicable_contexts', $context);

            if ($context === 'public') {
                $q->orWhereNull('applicable_contexts');
            }
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
    public function isEffective(?Carbon $date = null): bool
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
                'distance_types' => $this->distance_types,
                'applicable_contexts' => $this->applicable_contexts ?? self::DEFAULT_APPLICABLE_CONTEXTS,
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
        ?Carbon $date = null,
        ?string $ownerType = null,
        ?string $ownerId = null,
        ?string $distanceType = null,
        string $pricingContext = 'public'
    ): \Illuminate\Database\Eloquent\Collection {
        return static::queryApplicableRules(
            $distance, $serviceTypeId, $vehicleGroupId, $date, $ownerType, $ownerId,
            $distanceType ?? '', $pricingContext
        );
    }

    private static function queryApplicableRules(
        ?float $distance,
        ?string $serviceTypeId,
        ?string $vehicleGroupId,
        ?Carbon $date,
        ?string $ownerType,
        ?string $ownerId,
        string $distanceType,
        string $pricingContext,
        ?float $coverageDistance = null
    ): \Illuminate\Database\Eloquent\Collection {
        $query = static::active()
            ->effective($date)
            ->forDistanceType($distanceType)
            ->forPricingContext($pricingContext);
        if ($distance !== null) {
            $query->forDistance($distance);
        }
        if ($coverageDistance !== null) {
            $query->where('from_km', '<', $coverageDistance)
                ->where(fn (Builder $q) => $q->whereNull('to_km')->orWhere('to_km', '>', 0));
        }
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
        ?Carbon $date = null,
        ?string $ownerType = null,
        ?string $ownerId = null,
        ?string $distanceType = null,
        string $pricingContext = 'public',
        ?float $baseRatePerKm = null,
        ?float $alreadyPricedRatePerKm = null
    ): array {
        if ($distance <= 0) {
            return ['rules_applied' => [], 'total_adjustment' => 0, 'final_amount' => $baseAmount, 'calculation_summary' => 'No distance to price'];
        }

        $applicableRules = static::queryApplicableRules(
            null,
            $serviceTypeId,
            $vehicleGroupId,
            $date,
            $ownerType,
            $ownerId,
            $distanceType ?? '',
            $pricingContext,
            $distance
        );

        $appliedRules = [];
        $totalAdjustment = 0.0;
        $baseRatePerKm ??= $distance > 0 ? max(0, $baseAmount / $distance) : 0.0;
        $alreadyPricedRatePerKm ??= $baseRatePerKm;
        $boundaries = [0.0, $distance];
        foreach ($applicableRules as $rule) {
            foreach ([(float) $rule->from_km, $rule->to_km === null ? null : (float) $rule->to_km] as $boundary) {
                if ($boundary !== null && $boundary > 0 && $boundary < $distance) {
                    $boundaries[] = $boundary;
                }
            }
        }
        sort($boundaries, SORT_NUMERIC);
        $boundaries = array_values(array_unique($boundaries, SORT_REGULAR));
        $flatRulesApplied = [];

        for ($index = 1; $index < count($boundaries); $index++) {
            $from = $boundaries[$index - 1];
            $to = $boundaries[$index];
            $portion = $to - $from;
            $midpoint = ($from + $to) / 2;
            $rule = $applicableRules->first(fn (self $candidate): bool =>
                $midpoint >= (float) $candidate->from_km
                && ($candidate->to_km === null || $midpoint < (float) $candidate->to_km)
            );
            if (!$rule || $portion <= 0) {
                continue;
            }

            $alreadyPricedAmount = $portion * $alreadyPricedRatePerKm;
            if ($rule->price_type === 'flat_addition') {
                if (isset($flatRulesApplied[$rule->id])) continue;
                $chargedAmount = (float) $rule->flat_amount;
                $flatRulesApplied[$rule->id] = true;
                $calculation = "Flat addition in {$rule->from_km}-" . ($rule->to_km ?? '∞') . " km band = {$chargedAmount}";
            } else {
                $ratePerKm = $rule->price_type === 'fixed_rate'
                    ? (float) $rule->rate_per_km
                    : $baseRatePerKm * (float) $rule->percentage;
                $chargedAmount = $portion * $ratePerKm;
                $calculation = $rule->price_type === 'fixed_rate'
                    ? "{$portion} km × {$ratePerKm}/km = {$chargedAmount}"
                    : "{$portion} km × {$baseRatePerKm}/km × {$rule->percentage} = {$chargedAmount}";
            }

            $adjustment = $chargedAmount - $alreadyPricedAmount;
            if ($alreadyPricedAmount > 0) {
                $calculation .= "; replaces {$alreadyPricedAmount} base charge; net adjustment {$adjustment}";
            }
            $appliedRules[] = [
                'applicable' => true,
                'adjustment_amount' => $adjustment,
                'charged_amount' => $chargedAmount,
                'final_amount' => $baseAmount + $adjustment,
                'calculation_details' => [
                    'type' => $rule->price_type,
                    'distance' => $portion,
                    'distance_from_km' => $from,
                    'distance_to_km' => $to,
                    'base_rate_per_km' => $baseRatePerKm,
                    'rate_per_km' => $rule->price_type === 'fixed_rate' ? (float) $rule->rate_per_km : null,
                    'multiplier' => $rule->price_type === 'percentage_multiplier' ? (float) $rule->percentage : null,
                    'charged_amount' => $chargedAmount,
                    'already_priced_amount' => $alreadyPricedAmount,
                    'calculation' => $calculation,
                ],
                'rule_info' => [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'scope' => $rule->scope,
                    'distance_types' => $rule->distance_types,
                    'applicable_contexts' => $rule->applicable_contexts ?? self::DEFAULT_APPLICABLE_CONTEXTS,
                    'km_range' => "{$rule->from_km} - " . ($rule->to_km ?? '∞') . ' km',
                    'priority' => $rule->priority,
                ],
            ];
            $totalAdjustment += $adjustment;
        }

        $mergedRules = [];
        foreach ($appliedRules as $appliedRule) {
            $lastIndex = array_key_last($mergedRules);
            $lastRule = $lastIndex === null ? null : $mergedRules[$lastIndex];
            $lastDetails = $lastRule['calculation_details'] ?? [];
            $details = $appliedRule['calculation_details'];
            $canMerge = $lastRule
                && ($appliedRule['rule_info']['id'] ?? null) === ($lastRule['rule_info']['id'] ?? null)
                && ($details['type'] ?? null) !== 'flat_addition'
                && abs((float) ($lastDetails['distance_to_km'] ?? 0) - (float) ($details['distance_from_km'] ?? 0)) < 0.000001;

            if (!$canMerge) {
                $mergedRules[] = $appliedRule;
                continue;
            }

            $previous = &$mergedRules[$lastIndex];
            $previousDetails = &$previous['calculation_details'];
            $previous['adjustment_amount'] += $appliedRule['adjustment_amount'];
            $previous['charged_amount'] += $appliedRule['charged_amount'];
            $previous['final_amount'] = $baseAmount + $previous['adjustment_amount'];
            $previousDetails['distance'] += $details['distance'];
            $previousDetails['distance_to_km'] = $details['distance_to_km'];
            $previousDetails['charged_amount'] += $details['charged_amount'];
            $previousDetails['already_priced_amount'] += $details['already_priced_amount'];
            $distance = $previousDetails['distance'];
            $chargedAmount = $previousDetails['charged_amount'];
            $calculation = ($details['type'] === 'fixed_rate')
                ? "{$distance} km × {$details['rate_per_km']}/km = {$chargedAmount}"
                : "{$distance} km × {$details['base_rate_per_km']}/km × {$details['multiplier']} = {$chargedAmount}";
            if ($previousDetails['already_priced_amount'] > 0) {
                $calculation .= '; replaces ' . $previousDetails['already_priced_amount']
                    . ' base charge; net adjustment ' . $previous['adjustment_amount'];
            }
            $previousDetails['calculation'] = $calculation;
            unset($previous, $previousDetails);
        }
        $appliedRules = $mergedRules;

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
            'distance_types' => 'sometimes|array|min:1',
            'distance_types.*' => ['required', 'string', 'distinct', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'applicable_contexts' => 'required|array|min:1',
            'applicable_contexts.*' => 'required|string|distinct|in:portal,public,corporate',
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
