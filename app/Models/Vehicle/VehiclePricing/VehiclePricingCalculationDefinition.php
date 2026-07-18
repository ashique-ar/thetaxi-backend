<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\Service\ServiceType;
use App\Models\User;
use App\Models\Vehicle\VehiclePricing\Concerns\HasGlobalPricingDefinitionScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Models\Vehicle\VehiclePricing\PriceAdjustment;
use App\Models\Vehicle\VehiclePricing\KmRangePricingRule;
use App\Services\VehiclePricingSlabConfigurationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Vehicle Pricing Calculation Definition Model
 */
class VehiclePricingCalculationDefinition extends Model
{
    use HasFactory, HasGlobalPricingDefinitionScope, SoftDeletes;

    /** @var array<string, array<string, mixed>> */
    private array $resolvedRateSources = [];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'description',
        'service_type_id',
        'status',
        'formula',
        'variables',
        'conditions',
        'owner_type',
        'owner_id',
        'priority',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'variables' => 'array',
        'conditions' => 'array',
        'priority' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the service type associated with this calculation definition.
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * Get the user who created this calculation definition.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this calculation definition.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function calculatePrice(array $inputs, $appliedCustomizations = [], $servicePackageInfo = null, $districtInfo = null): array
    {
        try {
            $this->resolvedRateSources = [];
            $inputs = $this->normalizeDurationUnits($inputs);
            $this->assertValidDateWindow($inputs);

            $metadata = [
                'definition_id' => $this->id,
                'definition_name' => $this->name,
                'variables_declared' => array_map(fn($v) => $v['name'] ?? '', $this->variables ?? []),
                'variables_used' => [],
                'conditions_evaluated' => $this->conditions ?? [],
                'mode' => $inputs['mode'] ?? null,
                'service_package_info' => $servicePackageInfo,
                'district_info' => $districtInfo,
            ];

            if (!$this->evaluateConditions($inputs)) {
                $empty = $this->getEmptyCalculationResult();
                return array_merge($empty, [
                    'definition_id' => $this->id,
                    'variables_used' => [],
                    'resolved_variables' => [],
                    'conditions_evaluated' => $metadata['conditions_evaluated'],
                    'missing_variables' => [],
                ]);
            }

            $slabInfo = $this->getSlabInformation($inputs);

            $kmCalculations = $this->calculateKmOverages($inputs, $slabInfo, $servicePackageInfo);
            try {
                $resolvedVariables = $this->resolveAllVariables($inputs, $slabInfo, $kmCalculations, $appliedCustomizations, $servicePackageInfo, $districtInfo);

                $resolvedVariablesWithoutCustomizations = $this->resolveAllVariables($inputs, $slabInfo, $kmCalculations, [], $servicePackageInfo, $districtInfo);
            } catch (\InvalidArgumentException $ex) {
                // A definition with unresolved required inputs is not a valid
                // pricing candidate. In particular, do not let the caller
                // mistake this structured failure for a matched zero charge.
                Log::warning("Missing variables for calculation {$this->id}: " . $ex->getMessage());
                $missingVariables = str_starts_with($ex->getMessage(), 'Missing required variables: ')
                    ? array_values(array_filter(array_map(
                        'trim',
                        explode(',', Str::after($ex->getMessage(), 'Missing required variables: '))
                    )))
                    : [];

                return [
                    'total_amount' => 0,
                    'breakdown' => [],
                    'slab_info' => $slabInfo,
                    'km_calculations' => $kmCalculations,
                    'conditions_met' => false,
                    'calculation_success' => false,
                    'failure_reason' => 'missing_required_variables',
                    'definition_id' => $this->id,
                    'variables_used' => [],
                    'resolved_variables' => [],
                    'conditions_evaluated' => $metadata['conditions_evaluated'],
                    'missing_variables' => $missingVariables,
                ];
            }

            $metadata['variables_used'] = array_keys($resolvedVariables);

            $totalAmount = $this->evaluateFormulaWithVariables($this->formula, $resolvedVariables);
            $totalAmountWithoutCustomizations = $this->evaluateFormulaWithVariables($this->formula, $resolvedVariablesWithoutCustomizations);

            // Apply price adjustments and KM range pricing rules after base calculation
            $adjustmentResults = $this->applyPricingAdjustments(
                $totalAmount,
                $inputs,
                $servicePackageInfo,
                $districtInfo,
                $kmCalculations,
                $resolvedVariables
            );

            $finalAmount = $adjustmentResults['final_amount'];
            $finalAmountWithoutCustomizations = $this->applyPricingAdjustments(
                $totalAmountWithoutCustomizations,
                $inputs,
                $servicePackageInfo,
                $districtInfo,
                $kmCalculations,
                $resolvedVariablesWithoutCustomizations
            )['final_amount'];

            // A row-level minimum floors the completed formula. Applying it to
            // slab_rate itself would incorrectly multiply the minimum again in
            // per-hour or per-kilometre formulas.
            $minimumCharge = array_key_exists('slab_rate', $resolvedVariables)
                ? $this->getSlabMinimumCharge($inputs, $slabInfo)
                : null;
            if ($minimumCharge !== null && $finalAmount < $minimumCharge) {
                $adjustmentResults['adjustments'][] = [
                    'type' => 'minimum_charge',
                    'name' => 'Minimum Charge',
                    'description' => 'Vehicle group slab minimum charge',
                    'amount' => $minimumCharge - $finalAmount,
                    'calculation' => "Minimum {$minimumCharge} applied to {$finalAmount}",
                    'is_discount' => false,
                    'original_amount' => $finalAmount,
                    'adjusted_amount' => $minimumCharge,
                    'adjustment_amount' => $minimumCharge - $finalAmount,
                ];
                $finalAmount = $minimumCharge;
                $adjustmentResults['final_amount'] = $finalAmount;
            }
            if ($minimumCharge !== null && $finalAmountWithoutCustomizations < $minimumCharge) {
                $finalAmountWithoutCustomizations = $minimumCharge;
            }

            $built = $this->buildCalculationBreakdown(
                $resolvedVariables,
                $finalAmount,
                $finalAmountWithoutCustomizations,
                $slabInfo,
                $kmCalculations,
                $adjustmentResults
            );
            $built['definition_id'] = $this->id;
            $built['variables_used'] = $metadata['variables_used'];
            $built['resolved_variables'] = $resolvedVariables;
            $built['rate_sources'] = array_values($this->resolvedRateSources);
            $built['formula_evaluation'] = [
                'formula' => $this->formula,
                'substituted_formula' => $this->substituteFormulaVariables($this->formula, $resolvedVariables),
                'result_before_adjustments' => $totalAmount,
                'result_without_customizations_before_adjustments' => $totalAmountWithoutCustomizations,
            ];
            $built['conditions_evaluated'] = $metadata['conditions_evaluated'];
            $built['pricing_adjustments'] = $adjustmentResults['adjustments'];
            $built['service_package_info'] = $servicePackageInfo;
            $built['district_info'] = $districtInfo;

            return $built;
        } catch (\Exception $e) {
            Log::error("Price calculation failed for definition {$this->id}: " . $e->getMessage(), [
                'inputs' => $inputs,
                'variables' => $this->variables,
                'formula' => $this->formula
            ]);
            throw $e;
        }
    }

    /**
     * Make hour and minute duration variables interchangeable in formulas.
     * Explicit values are preserved; only a missing counterpart is derived.
     */
    private function normalizeDurationUnits(array $inputs): array
    {
        foreach (['duration', 'extra', 'waiting', 'recovery', 'overtime'] as $prefix) {
            $hoursKey = "{$prefix}_hours";
            $minutesKey = "{$prefix}_minutes";

            if (array_key_exists($minutesKey, $inputs) && !array_key_exists($hoursKey, $inputs)) {
                $inputs[$hoursKey] = (float) $inputs[$minutesKey] / 60;
            } elseif (array_key_exists($hoursKey, $inputs) && !array_key_exists($minutesKey, $inputs)) {
                $inputs[$minutesKey] = (float) $inputs[$hoursKey] * 60;
            }
        }

        if (array_key_exists('hours', $inputs) && !array_key_exists('duration_hours', $inputs)) {
            $inputs['duration_hours'] = (float) $inputs['hours'];
            $inputs['duration_minutes'] ??= (float) $inputs['hours'] * 60;
        }

        return $inputs;
    }

    /**
     * Build detailed calculation breakdown
     */
    private function buildCalculationBreakdown(array $resolvedVariables, float $totalAmount, float $totalAmountWithoutCustomizations, ?array $slabInfo, array $kmCalculations, array $adjustmentResults = []): array
    {
        $breakdown = [];

        // Base rate breakdown
        if (isset($resolvedVariables['slab_rate']) && $resolvedVariables['slab_rate'] > 0) {
            $slabDuration = 'Base rate';
            if ($slabInfo) {
                $slabDuration = match ($slabInfo['type'] ?? null) {
                    'minutes' => ($slabInfo['duration_minutes'] ?? 0) . ' minutes',
                    default => !empty($slabInfo['duration_days'])
                        ? $slabInfo['duration_days'] . ' days'
                        : ($slabInfo['duration_hours'] ?? 0) . ' hours',
                };
            }
            $breakdown[] = [
                'component' => 'base_rate',
                'description' => 'Base service rate',
                'amount' => $resolvedVariables['slab_rate'],
                'calculation' => $slabInfo
                    ? "Based on {$slabInfo['type']} slab for {$slabDuration}"
                    : 'Base rate',
                'details' => [
                    'slab_rate' => '',
                    'slab_price_id' => '',
                ]
            ];
        }

        $totalDeliveryCharge = 0;
        // Enhanced distance-based charges with detailed breakdown
        foreach (['delivery_distance', 'pickup_distance'] as $distanceType) {
            $distance = $resolvedVariables[$distanceType] ?? 0;
            //vehicle_delivery_rate_per_km
            $rateVar = str_replace('_distance', '_rate_per_km', $distanceType);
            $rateVar = "vehicle_{$rateVar}";

            $rate = $resolvedVariables[$rateVar] ?? 0;

            if ($distance > 0 && $rate > 0) {
                $amount = $distance * $rate;
                $serviceTypeLabel = $distanceType === 'pickup_distance' ? 'Vehicle Pickup' : 'Vehicle Delivery';
                $totalDeliveryCharge += $distance * $rate;
                $breakdown[] = [
                    'component' => $distanceType,
                    'description' => $serviceTypeLabel,
                    'amount' => $amount,
                    'calculation' => "{$distance} km × LKR {$rate}",
                    'details' => [
                        'distance_km' => $distance,
                        'rate_per_km' => $rate,
                        'category' => 'company_logistics',
                        'type' => str_replace('_distance', '', $distanceType)
                    ]
                ];
            } elseif ($distance > 0 && $rate == 0) {
                // Log when distance exists but no rate is found
                Log::warning("Distance found but no rate available", [
                    'distance_type' => $distanceType,
                    'distance' => $distance,
                    'rate_variable' => $rateVar,
                    'resolved_variables' => array_keys($resolvedVariables)
                ]);
            }
        }

        // Service distance charges (for KM-based services)
        if (isset($resolvedVariables['driver_allowance']) && isset($resolvedVariables['number_of_days'])) {
            $days = $resolvedVariables['number_of_days'];
            $rate = $resolvedVariables['driver_allowance'];
            if ($days > 0 && $rate > 0) {
                $amount = $days * $rate;
                $breakdown[] = [
                    'component' => 'driver_allowance',
                    'description' => 'Driver Alowance',
                    'amount' => $amount,
                    'calculation' => "{$days} days × LKR {$rate}"
                ];
            }
        }
        // Service distance charges (for KM-based services)
        if (isset($resolvedVariables['service_rate_per_km']) && isset($resolvedVariables['total_distance'])) {
            $distance = $resolvedVariables['total_distance'];
            $rate = $resolvedVariables['service_rate_per_km'];
            if ($distance > 0 && $rate > 0) {
                $amount = $distance * $rate;
                $breakdown[] = [
                    'component' => 'service_distance',
                    'description' => 'Service distance charges',
                    'amount' => $amount,
                    'calculation' => "{$distance} km × LKR {$rate}"
                ];
            }
        }

        // Extra KM charges
        if ($kmCalculations['extra_km'] > 0 && isset($resolvedVariables['extra_km_rate'])) {
            $rate = $resolvedVariables['extra_km_rate'];
            if ($rate > 0) {
                $amount = $kmCalculations['extra_km'] * $rate;
                $breakdown[] = [
                    'component' => 'extra_km',
                    'description' => 'Extra KM charges',
                    'amount' => $amount,
                    'calculation' => "{$kmCalculations['extra_km']} km × LKR {$rate}",
                    'note' => "Exceeded {$kmCalculations['calculation_type']} limit of {$kmCalculations['allowed_km']} km"
                ];
            }
        }

        // Other charges (decoration, waiting, stops, etc.)
        $otherCharges = [
            'decoration_charge' => 'Vehicle decoration',
            'waiting_charge_per_hour' => 'Waiting charges',
            'waiting_charge_per_minute' => 'Waiting charges',
            'stop_charge' => 'Stop charges',
            'emergency_base_rate' => 'Emergency base fee',
            'overtime_rate_per_hour' => 'Overtime charges',
            'overtime_rate_per_minute' => 'Overtime charges',
            'extra_minute_rate' => 'Extra time charges',
        ];

        $quantityVariables = [
            'waiting_charge_per_hour' => ['waiting_hours', 'hours'],
            'waiting_charge_per_minute' => ['waiting_minutes', 'minutes'],
            'overtime_rate_per_hour' => ['overtime_hours', 'hours'],
            'overtime_rate_per_minute' => ['overtime_minutes', 'minutes'],
            'extra_minute_rate' => ['extra_minutes', 'minutes'],
        ];

        foreach ($otherCharges as $varName => $description) {
            if (isset($resolvedVariables[$varName]) && $resolvedVariables[$varName] > 0) {
                $amount = $resolvedVariables[$varName];

                // Tie every rate to its own canonical quantity. Waiting values
                // must never leak into overtime examples just because both
                // rate names share the same unit suffix.
                if (isset($quantityVariables[$varName])) {
                    [$quantityVariable, $unit] = $quantityVariables[$varName];
                    $quantity = (float) ($resolvedVariables[$quantityVariable] ?? 0);
                    $amount = $quantity * $resolvedVariables[$varName];
                    $calculation = "{$quantity} {$unit} × LKR {$resolvedVariables[$varName]}";
                } elseif ($varName === 'stop_charge') {
                    $stops = (float) ($resolvedVariables['stops']
                        ?? $resolvedVariables['additional_stops']
                        ?? 0);
                    $amount = $stops * $resolvedVariables[$varName];
                    $calculation = "{$stops} stops × LKR {$resolvedVariables[$varName]}";
                } else {
                    $calculation = "LKR {$amount}";
                }

                if ($amount > 0) {
                    $breakdown[] = [
                        'component' => $varName,
                        'description' => $description,
                        'amount' => $amount,
                        'calculation' => $calculation
                    ];
                }
            }
        }

        // Corporate discounts
        if (isset($resolvedVariables['discount_percentage']) && $resolvedVariables['discount_percentage'] > 0) {
            $discountAmount = ($resolvedVariables['slab_rate'] ?? 0) * $resolvedVariables['discount_percentage'];
            if ($discountAmount > 0) {
                $discountPercent = $resolvedVariables['discount_percentage'] * 100;
                $breakdown[] = [
                    'component' => 'corporate_discount',
                    'description' => 'Corporate discount',
                    'amount' => -$discountAmount,
                    'calculation' => "{$discountPercent}% discount"
                ];
            }
        }

        foreach ($adjustmentResults['adjustments'] ?? [] as $index => $adjustment) {
            $breakdown[] = [
                'component' => $adjustment['type'] ?? "pricing_adjustment_{$index}",
                'description' => $adjustment['name']
                    ?? $adjustment['description']
                    ?? 'Pricing adjustment',
                'amount' => (float) ($adjustment['amount'] ?? $adjustment['adjustment_amount'] ?? 0),
                'calculation' => $adjustment['calculation'] ?? null,
            ];
        }

        return [
            'total_amount' => $totalAmount,
            'subtotal' => $totalAmount,
            'total_amount_without_customizations' => $totalAmountWithoutCustomizations,
            'breakdown' => $breakdown,
            'slab_info' => $slabInfo,
            'km_calculations' => $kmCalculations,
            'conditions_met' => true,
            'calculation_success' => true,
            'calculation_summary' => [
                'base_amount' => $resolvedVariables['slab_rate'] ?? 0,
                'delivery_pickup_total' => $totalDeliveryCharge,
                'additional_charges' => $totalAmount - ($resolvedVariables['slab_rate'] ?? 0) - $totalDeliveryCharge,
                'variables_used' => array_keys($resolvedVariables)
            ],
            // Include adjustment details for frontend discount display
            'adjustment_details' => [
                'original_amount' => $adjustmentResults['original_amount'] ?? $totalAmount,
                'final_amount' => $adjustmentResults['final_amount'] ?? $totalAmount,
                'total_adjustment' => $adjustmentResults['total_adjustment'] ?? 0,
                'total_discount' => $adjustmentResults['total_discount'] ?? 0,
                'total_increase' => $adjustmentResults['total_increase'] ?? 0,
                'has_discount' => $adjustmentResults['has_discount'] ?? false,
                'has_increase' => $adjustmentResults['has_increase'] ?? false,
                'savings_display' => $adjustmentResults['savings_display'] ?? null,
                'discount_percentage' => $adjustmentResults['discount_percentage'] ?? 0,
                'adjustments' => $adjustmentResults['adjustments'] ?? [],
            ],
        ];
    }

    /**
     * Get empty calculation result structure
     */
    private function getEmptyCalculationResult(): array
    {
        return [
            'total_amount' => 0,
            'breakdown' => [],
            'slab_info' => null,
            'km_calculations' => null,
            'conditions_met' => false,
            'calculation_success' => false,
            'failure_reason' => 'conditions_not_met',
            'service_package' => null,
            'km_range_pricing' => null,
            'price_adjustments' => null,
            'base_amount' => 0,
        ];
    }

    /**
     * Get slab information including KM limits
     */
    private function getSlabInformation(array $inputs): ?array
    {
        $vehicleGroupId = $inputs['vehicle_group_id'] ?? null;
        $durationHours = $inputs['duration_hours'] ?? $inputs['hours'] ?? 0;
        $durationMinutes = $inputs['duration_minutes'] ?? ($durationHours * 60);
        $durationDays = $inputs['duration_days'] ?? $inputs['days'] ?? 0;
        $ownerType = $inputs['owner_type'] ?? null;
        $ownerId = $inputs['owner_id'] ?? null;

        if (!$vehicleGroupId) {
            return null;
        }

        // Convert days to hours if needed
        if ($durationDays > 0 && $durationHours == 0) {
            $durationHours = $durationDays * 24;
            $durationMinutes = $durationHours * 60;
        }

        $slabQuery = VehiclePricingSlabDefinition::where('service_type_id', $this->service_type_id)
            ->where('is_active', true)
            ->tap(fn ($query) => $this->applyOwnerScope($query, $ownerType, $ownerId))
            ->tap(fn ($query) => $this->applyOwnerPriorityOrder($query, $ownerType, $ownerId));

        $slabDefinition = app(VehiclePricingSlabConfigurationService::class)->resolve(
            $slabQuery,
            (float) $durationMinutes,
            $durationDays > 0 ? (float) $durationDays : null
        );

        if (!$slabDefinition) {
            return null;
        }

        return [
            'slab_definition' => $slabDefinition,
            'duration_hours' => $durationHours,
            'duration_minutes' => $durationMinutes,
            'duration_days' => $durationDays,
            'max_km_per_day' => $slabDefinition->max_km_per_day,
            'max_km_per_package' => $slabDefinition->max_km_per_package,
            'type' => $slabDefinition->type
        ];
    }

    /**
     * Calculate KM overages based on limits with enhanced daily package logic
     */
    private function calculateKmOverages(array $inputs, ?array $slabInfo, ?array $servicePackageInfo = null): array
    {
        $distanceSupplied = (array_key_exists('journey_distance', $inputs) && is_numeric($inputs['journey_distance']))
            || (array_key_exists('total_distance', $inputs) && is_numeric($inputs['total_distance']));
        $journeyDistance = $distanceSupplied
            ? (float) ($inputs['journey_distance'] ?? $inputs['total_distance'])
            : null;
        $result = [
            'journey_distance' => $journeyDistance,
            'distance_supplied' => $distanceSupplied,
            'allowance_supplied' => false,
            'allowed_km' => null,
            'extra_km' => null,
            'daily_overage' => 0,
            'package_overage' => 0,
            'calculation_type' => 'none',
            'calendar_days' => 0,
            'effective_days' => 0
        ];

        if (
            array_key_exists('package_included_km', $inputs)
            && is_numeric($inputs['package_included_km'])
            && (float) $inputs['package_included_km'] >= 0
        ) {
            $result['allowance_supplied'] = true;
            $result['allowed_km'] = (float) $inputs['package_included_km'];
            $result['extra_km'] = $journeyDistance !== null
                ? max(0, $journeyDistance - $result['allowed_km'])
                : null;
            $result['package_overage'] = $result['extra_km'];
            $result['calculation_type'] = 'package';
            $result['calendar_days'] = $this->calculateCalendarDays($inputs);
            $result['effective_days'] = max(1, $result['calendar_days']);

            return $result;
        }

        Log::debug('Calculating KM overages - Initial', [
            'journey_distance' => $result['journey_distance'],
            'result' => $result,
            'slab_info' => $slabInfo,
            'service_package_info' => $servicePackageInfo,
            'inputs' => $inputs
        ]);

        // Package allowances belong to the selected service package, not to a
        // duration slab. Resolve them before the slab guard so fixed/common
        // rate packages still enforce their included kilometres.
        if ($servicePackageInfo) {
            $calendarDays = $this->calculateCalendarDays($inputs);
            $result['calendar_days'] = $calendarDays;
            $result['effective_days'] = max(1, $calendarDays);

            $maxKmPerDay = $servicePackageInfo['max_km_per_day'] ?? null;
            $maxKmPerPackage = $servicePackageInfo['max_km_per_package'] ?? null;

            if (is_numeric($maxKmPerDay) && (float) $maxKmPerDay >= 0) {
                $result['allowance_supplied'] = true;
                $result['calculation_type'] = 'daily';
                $result['allowed_km'] = (float) $maxKmPerDay * $result['effective_days'];
                $result['extra_km'] = $journeyDistance !== null
                    ? max(0, $journeyDistance - $result['allowed_km'])
                    : null;
                $result['package_overage'] = $result['extra_km'];

                return $result;
            }

            if (is_numeric($maxKmPerPackage) && (float) $maxKmPerPackage >= 0) {
                $result['allowance_supplied'] = true;
                $result['calculation_type'] = 'package';
                $result['allowed_km'] = (float) $maxKmPerPackage;
                $result['extra_km'] = $journeyDistance !== null
                    ? max(0, $journeyDistance - $result['allowed_km'])
                    : null;
                $result['package_overage'] = $result['extra_km'];

                return $result;
            }
        }

        if (!$slabInfo) {
            return $result;
        }

        Log::debug('Calculating KM overages', [
            'journey_distance' => $result['journey_distance'],
            'slab_info' => $slabInfo,
            'service_package_info' => $servicePackageInfo,
            'inputs' => $inputs
        ]);
        $actualKm = $journeyDistance;

        // Enhanced daily calculation for calendar days
        $calendarDays = $this->calculateCalendarDays($inputs);
        $result['calendar_days'] = $calendarDays;
        $result['effective_days'] = max(1, $calendarDays); // Minimum 1 day


        $slabPackageAllowance = $slabInfo['max_km_per_package'] ?? null;
        $slabDailyAllowance = $slabInfo['max_km_per_day'] ?? null;
        if (
            is_numeric($slabPackageAllowance)
            && (float) $slabPackageAllowance >= 0
            && in_array($slabInfo['type'] ?? null, ['flat_rate', 'package'], true)
        ) {
            $result['allowance_supplied'] = true;
            // Package-based limit (e.g., wedding packages, airport transfers)
            $result['calculation_type'] = 'package';
            $result['allowed_km'] = (float) $slabPackageAllowance;
            $result['extra_km'] = $actualKm !== null
                ? max(0, $actualKm - $result['allowed_km'])
                : null;
            $result['package_overage'] = $result['extra_km'];
        } elseif (
            is_numeric($slabDailyAllowance)
            && (float) $slabDailyAllowance >= 0
            && $result['effective_days'] > 0
        ) {
            $result['allowance_supplied'] = true;
            // Daily-based limit (e.g., rental services, point to point, ride now)
            // For multi-day bookings: total allowance = max km × number of calendar days
            $result['calculation_type'] = 'daily';
            $result['allowed_km'] = (float) $slabDailyAllowance * $result['effective_days'];
            $result['extra_km'] = $actualKm !== null
                ? max(0, $actualKm - $result['allowed_km'])
                : null;
            $result['daily_overage'] = $result['extra_km'];
        } else {
            // No limits (e.g., KM-based services like point-to-point transfers)
            $result['calculation_type'] = 'unlimited';
            $result['allowed_km'] = null;
            // With no configured allowance there is no overage boundary. A
            // journey therefore has zero extra kilometres even when final
            // mileage is unavailable. There is no boundary to exceed.
            $result['extra_km'] = 0.0;
        }
        Log::debug('KM overages calculated', $result);
        return $result;
    }

    /**
     * Calculate calendar days between start and end dates
     * Handles partial day logic: if customer takes vehicle in evening, 
     * remaining hours until midnight count as first day
     * 
     * Enhanced logic for daily package calculations:
     * - Same day bookings = 1 day minimum
     * - Multi-day bookings count calendar days, not 24-hour periods  
     * - Evening pickup (e.g., 8 PM) + next day = 2 days
     */
    private function calculateCalendarDays(array $inputs): int
    {
        // Try to get dates from various input formats
        $fromDate = $inputs['from_date'] ?? $inputs['start_date'] ?? null;
        $toDate = $inputs['to_date'] ?? $inputs['end_date'] ?? null;
        $fromTime = $inputs['from_time'] ?? '00:00';
        $toTime = $inputs['to_time'] ?? '23:59';

        if (!$fromDate || !$toDate) {
            // Fallback to duration_days if dates not available
            return $inputs['duration_days'] ?? $inputs['days'] ?? 1;
        }

        // Do not let Carbon's absolute diff turn a reversed booking window
        // into a valid positive day count. This also protects direct callers
        // of this helper, not only the public calculatePrice() entry point.
        $this->assertValidDateWindow($inputs);

        try {
            // Parse dates and times with proper timezone handling
            $startDateTime = $this->combinePricingDateAndTime($fromDate, $fromTime, '00:00');
            $endDateTime = $this->combinePricingDateAndTime($toDate, $toTime, '23:59');

            // For daily packages, we count calendar days not 24-hour periods
            // This means if you take a car on Dec 1st at 8 PM and return on Dec 2nd at 6 PM,
            // that counts as 2 days, not 1.83 days

            // Get the date components (ignoring time)
            $startDate = $startDateTime->toDateString();
            $endDate = $endDateTime->toDateString();

            // Calculate calendar days between start and end dates (inclusive)
            $startDateOnly = Carbon::parse($startDate);
            $endDateOnly = Carbon::parse($endDate);

            $calendarDays = $startDateOnly->diffInDays($endDateOnly) + 1;

            // Special logic for same-day bookings
            if ($startDate === $endDate) {
                // Same day = 1 day minimum, regardless of hours
                return 1;
            }

            // For multi-day bookings, count all calendar days touched
            // Example: Dec 1 evening to Dec 3 morning = 3 days (Dec 1, Dec 2, Dec 3)
            return max(1, $calendarDays);
        } catch (\Exception $e) {
            Log::warning("Failed to parse dates for calendar day calculation", [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'from_time' => $fromTime,
                'to_time' => $toTime,
                'error' => $e->getMessage()
            ]);

            // Fallback to provided duration
            return $inputs['duration_days'] ?? $inputs['days'] ?? 1;
        }
    }

    private function assertValidDateWindow(array $inputs): void
    {
        $fromDate = $inputs['from_date'] ?? $inputs['start_date'] ?? null;
        $toDate = $inputs['to_date'] ?? $inputs['end_date'] ?? null;
        if (!$fromDate || !$toDate) {
            return;
        }

        $fromTime = $inputs['from_time'] ?? '00:00';
        $toTime = $inputs['to_time'] ?? '23:59';
        $start = $this->combinePricingDateAndTime($fromDate, $fromTime, '00:00');
        $end = $this->combinePricingDateAndTime($toDate, $toTime, '23:59');
        if ($end->lessThan($start)) {
            throw new \InvalidArgumentException(
                'Pricing end date and time must be on or after the start date and time.'
            );
        }
    }

    private function combinePricingDateAndTime(mixed $date, mixed $time, string $defaultTime): Carbon
    {
        $dateTime = Carbon::parse($date);
        $timeValue = $time === null || $time === '' ? $defaultTime : $time;
        $parsedTime = Carbon::parse($timeValue);

        return $dateTime->setTime(
            $parsedTime->hour,
            $parsedTime->minute,
            $parsedTime->second,
            $parsedTime->micro
        );
    }

    /**
     * Resolve all variables for calculation strictly as per definition with Service Package support.
     */
    private function resolveAllVariables(array $inputs, ?array $slabInfo, array $kmCalculations, ?array $appliedCustomizations, ?array $servicePackageInfo = null, ?array $districtInfo = null): array
    {
        $resolvedVariables = [];
        $missingVariables = [];

        foreach ($this->variables ?? [] as $variable) {

            $varName = $variable['name'];
            // Only variables referenced by the active formula are runtime
            // dependencies. Configuration health reports retained editor or
            // legacy variables as unused without blocking a valid formula.
            if (!$this->formulaReferencesVariable($varName)) {
                continue;
            }
            $varType = $variable['type'] ?? 'number';
            $isRequired = $variable['is_required'] ?? true;
            $defaultValue = $variable['default_value'] ?? null;
            // Definition-driven variables only

            if ($varName === 'extra_km') {
                $value = array_key_exists('extra_km', $kmCalculations)
                    ? $kmCalculations['extra_km']
                    : null;
                if ($value === null && !$isRequired) {
                    $value = $defaultValue;
                }
            } elseif ($varName === 'allowed_km') {
                $value = array_key_exists('allowed_km', $kmCalculations)
                    ? $kmCalculations['allowed_km']
                    : null;
                if ($value === null && !$isRequired) {
                    $value = $defaultValue;
                }
            } elseif ($varName === 'journey_distance') {
                // journey_distance comes from kmCalculations first, then inputs
                $value = $kmCalculations['journey_distance'] ?? ($inputs['journey_distance'] ?? null);
                if ($value === null && !$isRequired) {
                    $value = $defaultValue;
                }
            } elseif ($varName === 'total_distance') {
                // total_distance should come from inputs (includes pickup+journey+delivery), NOT kmCalculations
                $value = $inputs['total_distance'] ?? ($kmCalculations['total_distance'] ?? null);
                if ($value === null && !$isRequired) {
                    $value = $defaultValue;
                }
            } else {
                $runtimeDefault = $isRequired && in_array($varType, ['duration', 'distance'], true)
                    ? null
                    : $defaultValue;
                $value = $this->resolveVariable($varName, $varType, $inputs, $runtimeDefault, $slabInfo, $appliedCustomizations, $servicePackageInfo, $districtInfo);
            }

            $isPricingRate = in_array($varType, ['slab_rate', 'common_rate'], true);
            if ($value === null && $isPricingRate) {
                // Price rows are configured after the reusable calculation
                // definition. Missing slab/common-rate values therefore
                // contribute zero; configuration health reports the warning.
                $value = 0;
            }

            if ($value === null && $isRequired) {
                $missingVariables[] = $varName;
                continue;
            }

            $resolvedVariables[$varName] = $value ?? 0;
        }

        if (!empty($missingVariables)) {
            throw new \InvalidArgumentException(
                "Missing required variables: " . implode(', ', $missingVariables)
            );
        }
        return $resolvedVariables;
    }

    /**
     * Resolve a single variable value based on its type.
     * Enhanced to support Service Package pricing and district adjustments.
     *
     * @param string $varName Variable name
     * @param string $varType Variable type (slab_rate, common_rate, duration, distance, fixed_value)
     * @param array $inputs Input values
     * @param mixed $defaultValue Default value if not found in inputs
     * @param array|null $slabInfo Slab information for enhanced calculations
     * @param array|null $appliedCustomizations Custom value overrides
     * @param array|null $servicePackageInfo Service package context
     * @param array|null $districtInfo District pricing context
     * @return float|null Resolved value
     */
    private function resolveVariable(string $varName, string $varType, array $inputs, $defaultValue = null, ?array $slabInfo = null, ?array $appliedCustomizations = null, ?array $servicePackageInfo = null, ?array $districtInfo = null): ?float
    {

        $override = null;
        if (is_array($appliedCustomizations) && !empty($appliedCustomizations)) {
            foreach (array_reverse($appliedCustomizations) as $c) {
                if (($c['variable_name'] ?? null) === $varName && isset($c['custom_value']) && is_numeric($c['custom_value'])) {
                    $override = (float) $c['custom_value'];
                    break;
                }
            }
        }

        switch ($varType) {
            case 'slab_rate':
                return $this->getSlabRateValue($inputs, $slabInfo, $appliedCustomizations, $servicePackageInfo, $districtInfo);

            case 'common_rate':
                // Extract rate key from variable name (e.g., 'pickup_rate_per_km' from 'common_rate_pickup_rate_per_km')
                if ($override !== null) {
                    return $override;
                }
                $rateKey = str_starts_with($varName, 'common_rate_') ? substr($varName, 12) : $varName;
                // Test/runtime callers may preload trusted Pricing Management
                // values. Prefer that resolved value instead of querying again.
                if (array_key_exists($rateKey, $inputs) && is_numeric($inputs[$rateKey])) {
                    return (float) $inputs[$rateKey];
                }
                return $this->getCommonRateValue($rateKey, $inputs);

            case 'duration':
            case 'distance':
            case 'number':
            case 'fixed_value':
                // For these types, get the value directly from inputs or use default
                if ($override !== null) {
                    return $override;
                }
                $value = $inputs[$varName] ?? $defaultValue;
                return is_numeric($value) ? (float) $value : null;

            case 'calculated':
                // For calculated variables, check if there's a calculation method
                $methodName = 'calculate' . str_replace('_', '', ucwords($varName, '_'));
                if (method_exists($this, $methodName)) {
                    return $this->$methodName($inputs);
                }
                return $inputs[$varName] ?? $defaultValue;

            default:
                return $inputs[$varName] ?? $defaultValue;
        }
    }

    /**
     * Evaluate formula with resolved variables using a safe expression evaluator.
     *
     * @param string $formula Mathematical formula
     * @param array $variables Resolved variables
     * @return float|null Calculated result
     */
    private function evaluateFormulaWithVariables(string $formula, array $variables): ?float
    {
        try {
            $validationErrors = self::validateFormulaAgainstVariableNames($formula, array_keys($variables));
            if ($validationErrors !== []) {
                throw new \InvalidArgumentException(implode(' ', $validationErrors));
            }

            // Every value used by a pricing formula must be explicitly numeric.
            // Silently changing an invalid value into zero can undercharge.
            $numericVars = [];
            foreach ($variables as $k => $v) {
                if (!is_numeric($v)) {
                    throw new \InvalidArgumentException("Formula variable {$k} must be numeric.");
                }
                $numericVars[$k] = $v + 0; // +0 casts to int/float
            }

            // Replace {var} or bare var names as whole identifiers only (no partials)
            $evaluableFormula = preg_replace_callback(
                '/\{([A-Za-z_][A-Za-z0-9_]*)\}|(?<![A-Za-z0-9_])([A-Za-z_][A-Za-z0-9_]*)(?![A-Za-z0-9_])/',
                function ($m) use ($numericVars) {
                    $name = $m[1] !== '' ? $m[1] : $m[2];
                    if (in_array($name, ['max', 'min'], true)) {
                        return $name;
                    }
                    $val = $numericVars[$name];
                    return (string) (float) $val;
                },
                $formula
            );

            return $this->evaluateFormula($evaluableFormula);
        } catch (\Exception $e) {
            Log::error("Formula evaluation with variables failed: " . $e->getMessage(), [
                'original_formula' => $formula,
                'variables' => $variables,
                'evaluable_formula' => $evaluableFormula ?? 'N/A'
            ]);
            throw $e;
        }
    }

    private function substituteFormulaVariables(string $formula, array $variables): string
    {
        return preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}|(?<![A-Za-z0-9_])([A-Za-z_][A-Za-z0-9_]*)(?![A-Za-z0-9_])/',
            static function (array $match) use ($variables): string {
                $name = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');

                if (in_array($name, ['max', 'min'], true)) {
                    return $name;
                }

                return array_key_exists($name, $variables) && is_numeric($variables[$name])
                    ? (string) ((float) $variables[$name])
                    : $match[0];
            },
            $formula
        ) ?? $formula;
    }


    private function getSlabRateValue(array $inputs, ?array $slabInfo = null, ?array $appliedCustomizations = null, ?array $servicePackageInfo = null, ?array $districtInfo = null): ?float
    {
        try {
            $vehicleGroupId = $inputs['vehicle_group_id'] ?? null;

            $customSlabBase = null;
            if (is_array($appliedCustomizations) && !empty($appliedCustomizations)) {
                foreach (array_reverse($appliedCustomizations) as $c) {
                    if (($c['variable_name'] ?? null) === 'slab_rate' && isset($c['custom_value']) && is_numeric($c['custom_value'])) {
                        $customSlabBase = (float) $c['custom_value'];
                        break;
                    }
                }
            }

            if (!$vehicleGroupId) {
                Log::warning("No vehicle group ID provided for slab rate calculation");
                if ($customSlabBase !== null) {
                    $this->resolvedRateSources['slab_rate'] = [
                        'variable' => 'slab_rate',
                        'source_type' => 'approved_customization',
                        'configured_value' => $customSlabBase,
                        'resolved_value' => $customSlabBase,
                    ];
                }
                return $customSlabBase;
            }

            $durationHours = $slabInfo['duration_hours'] ?? $inputs['duration_hours'] ?? $inputs['hours'] ?? 0;
            if (!$durationHours && isset($inputs['package_default_duration_hours'])) {
                $durationHours = $inputs['package_default_duration_hours'];
            }

            // Use pre-calculated slab info if available
            if ($slabInfo && isset($slabInfo['slab_definition'])) {
                $slabDefinition = $slabInfo['slab_definition'];
            } else {
                if (!$durationHours && $customSlabBase !== null) {
                    // Explicit override without duration
                    $this->resolvedRateSources['slab_rate'] = [
                        'variable' => 'slab_rate',
                        'source_type' => 'approved_customization',
                        'configured_value' => $customSlabBase,
                        'resolved_value' => $customSlabBase,
                    ];
                    return $customSlabBase;
                }

                $durationDaysForFallback = $inputs['duration_days'] ?? $inputs['days'] ?? 0;
                $ownerType = $inputs['owner_type'] ?? null;
                $ownerId = $inputs['owner_id'] ?? null;
                $slabDefinition = VehiclePricingSlabDefinition::where('service_type_id', $this->service_type_id)
                    ->where('is_active', true)
                    ->forOwner($ownerType, $ownerId)
                    ->where(function ($query) use ($durationHours, $durationDaysForFallback) {
                        $query->when($durationDaysForFallback > 0, function ($q) use ($durationDaysForFallback) {
                            return $q->where('min_days', '<=', $durationDaysForFallback)
                                ->where(function ($subQ) use ($durationDaysForFallback) {
                                    $subQ->whereNull('max_days')
                                        ->orWhere('max_days', '>=', $durationDaysForFallback);
                                });
                        })->when($durationHours > 0 && $durationDaysForFallback == 0, function ($q) use ($durationHours) {
                            return $q->where('min_hours', '<=', $durationHours)
                                ->where(function ($subQ) use ($durationHours) {
                                    $subQ->whereNull('max_hours')
                                        ->orWhere('max_hours', '>=', $durationHours);
                                });
                        });
                    })
                    ->orderByDesc('min_days')
                    ->orderByDesc('min_hours')
                    ->tap(fn ($query) => $this->applyOwnerPriorityOrder($query, $ownerType, $ownerId))
                    ->orderByDesc('priority')
                    ->first();

                if (!$slabDefinition) {
                    Log::warning("No slab definition found for service type {$this->service_type_id} and duration {$durationHours}h");
                    return $customSlabBase;
                }
            }

            // If we only have a custom override (e.g. package) return it directly
            if (!$slabDefinition && $customSlabBase !== null) {
                return $customSlabBase;
            }

            $vehicleGroupPricing = $slabDefinition
                ? VehicleGroupPricing::where('vehicle_group_id', $vehicleGroupId)
                    ->where('slab_definition_id', $slabDefinition->id)
                    ->where('is_active', true)
                    ->forOwner($inputs['owner_type'] ?? null, $inputs['owner_id'] ?? null)
                    ->tap(fn ($query) => $this->applyOwnerPriorityOrder($query, $inputs['owner_type'] ?? null, $inputs['owner_id'] ?? null))
                    ->orderByDesc('priority')
                    ->first()
                : null;

            if (!$vehicleGroupPricing) {
                // If no pricing row but we *do* have a custom base rate, treat it as a flat amount
                if ($customSlabBase !== null) {
                    $this->resolvedRateSources['slab_rate'] = [
                        'variable' => 'slab_rate',
                        'source_type' => 'approved_customization',
                        'slab_definition_id' => $slabDefinition?->id,
                        'configured_value' => $customSlabBase,
                        'resolved_value' => $customSlabBase,
                    ];
                    return $customSlabBase;
                }
                return null;
            }

            // Use customized base rate when provided; otherwise DB base rate
            $baseRate = $customSlabBase !== null
                ? $customSlabBase
                : (float) $vehicleGroupPricing->rate;

            // Apply Service Package multiplier to base rate ONLY
            if ($servicePackageInfo && isset($servicePackageInfo['price_multiplier']) && $servicePackageInfo['price_multiplier'] > 0) {
                $multiplier = (float) $servicePackageInfo['price_multiplier'];
                $baseRate = $baseRate * $multiplier;
            }

            // Apply district pricing adjustment to base rate
            if ($districtInfo && isset($districtInfo['percentage_change']) && $districtInfo['percentage_change'] != 0) {
                $districtAdjustment = 1 + ($districtInfo['percentage_change'] / 100);
                $baseRate = $baseRate * $districtAdjustment;
            }

            $this->resolvedRateSources['slab_rate'] = [
                'variable' => 'slab_rate',
                'source_type' => 'vehicle_group_slab_pricing',
                'pricing_id' => (string) $vehicleGroupPricing->id,
                'slab_definition_id' => (string) $slabDefinition->id,
                'vehicle_group_id' => (string) $vehicleGroupPricing->vehicle_group_id,
                'owner_type' => $vehicleGroupPricing->owner_type,
                'owner_id' => $vehicleGroupPricing->owner_id,
                'rate_type' => $vehicleGroupPricing->rate_type,
                'configured_value' => (float) $vehicleGroupPricing->rate,
                'customized_value' => $customSlabBase,
                'package_multiplier' => $servicePackageInfo['price_multiplier'] ?? null,
                'district_percentage_change' => $districtInfo['percentage_change'] ?? null,
                'resolved_value' => $baseRate,
            ];

            return $baseRate;
        } catch (\Exception $e) {
            Log::error("Error getting slab rate value: " . $e->getMessage(), [
                'service_type_id' => $this->service_type_id,
                'inputs' => $inputs
            ]);
            return null;
        }
    }

    private function getSlabMinimumCharge(array $inputs, ?array $slabInfo): ?float
    {
        $vehicleGroupId = $inputs['vehicle_group_id'] ?? null;
        $slabDefinition = $slabInfo['slab_definition'] ?? null;
        if (!$vehicleGroupId || !$slabDefinition) {
            return null;
        }

        $ownerType = $inputs['owner_type'] ?? null;
        $ownerId = $inputs['owner_id'] ?? null;
        $pricing = VehicleGroupPricing::where('vehicle_group_id', $vehicleGroupId)
            ->where('slab_definition_id', $slabDefinition->id)
            ->where('is_active', true)
            ->forOwner($ownerType, $ownerId)
            ->tap(fn ($query) => $this->applyOwnerPriorityOrder($query, $ownerType, $ownerId))
            ->orderByDesc('priority')
            ->first();

        return $pricing?->minimum_charge !== null
            ? (float) $pricing->minimum_charge
            : null;
    }

    /**
     * Get the configured vehicle-group common-rate value.
     * Definitions describe the rate; they never provide a billable fallback.
     *
     * @param string $rateKey The common rate identifier
     * @param array $inputs Input values containing vehicle_group_id
     * @return float|null The common rate value, or null when no configured rate exists
     */
    private function getCommonRateValue(string $rateKey, array $inputs): ?float
    {
        try {
            $vehicleGroupId = $inputs['vehicle_group_id'] ?? null;

            if (!$vehicleGroupId) {
                Log::warning("No vehicle_group_id provided for common rate lookup: {$rateKey}");
                return null;
            }
            $ownerType = $inputs['owner_type'] ?? null;
            $ownerId = $inputs['owner_id'] ?? null;

            // Get vehicle group specific common rate pricing first
            $commonRatePricing = VehicleGroupCommonRatePricing::with('commonRateDefinition')
                ->whereHas('commonRateDefinition', function ($query) use ($rateKey) {
                $query->where(function ($q) use ($rateKey) {
                    $q->where('code', $rateKey)
                        ->orWhere('name', $rateKey);
                })
                    ->where(function ($serviceQuery) {
                        $serviceQuery->where('service_type_id', $this->service_type_id)
                            ->orWhereNull('service_type_id');
                    })
                    ->where('is_active', true);
            })
                ->where('vehicle_group_id', $vehicleGroupId)
                ->where('is_active', true)
                ->when($ownerType && $ownerId, function ($query) use ($ownerType, $ownerId) {
                    $this->applyOwnerScope($query, $ownerType, $ownerId);
                }, fn ($query) => $query->whereNull('owner_type')->whereNull('owner_id'))
                ->tap(fn ($query) => $this->applyOwnerPriorityOrder($query, $ownerType, $ownerId))
                ->orderBy('priority', 'desc')
                ->get()
                ->sort(function ($left, $right) use ($ownerType, $ownerId) {
                    $rank = function ($pricing) use ($ownerType, $ownerId): array {
                        $definition = $pricing->commonRateDefinition;
                        $ownerExact = $ownerType && $ownerId
                            && $pricing->owner_type === $ownerType
                            && (string) $pricing->owner_id === (string) $ownerId;

                        return [
                            (string) $definition?->service_type_id === (string) $this->service_type_id ? 0 : 1,
                            $ownerExact ? 0 : 1,
                            -((int) $pricing->priority),
                            -((int) ($definition?->priority ?? 0)),
                            (string) $pricing->id,
                        ];
                    };

                    return $rank($left) <=> $rank($right);
                })
                ->first();

            if ($commonRatePricing && isset($commonRatePricing->value)) {
                $value = (float) $commonRatePricing->value;
                $this->resolvedRateSources[$rateKey] = [
                    'variable' => $rateKey,
                    'source_type' => 'vehicle_group_common_rate_pricing',
                    'pricing_id' => (string) $commonRatePricing->id,
                    'common_rate_definition_id' => (string) $commonRatePricing->common_rate_definition_id,
                    'common_rate_code' => $commonRatePricing->commonRateDefinition?->code,
                    'vehicle_group_id' => (string) $commonRatePricing->vehicle_group_id,
                    'owner_type' => $commonRatePricing->owner_type,
                    'owner_id' => $commonRatePricing->owner_id,
                    'configured_value' => $value,
                    'resolved_value' => $value,
                ];
                return $value;
            } elseif ($commonRatePricing && isset($commonRatePricing->rate)) {
                $value = (float) $commonRatePricing->rate;
                $this->resolvedRateSources[$rateKey] = [
                    'variable' => $rateKey,
                    'source_type' => 'vehicle_group_common_rate_pricing',
                    'pricing_id' => (string) $commonRatePricing->id,
                    'common_rate_definition_id' => (string) $commonRatePricing->common_rate_definition_id,
                    'common_rate_code' => $commonRatePricing->commonRateDefinition?->code,
                    'vehicle_group_id' => (string) $commonRatePricing->vehicle_group_id,
                    'owner_type' => $commonRatePricing->owner_type,
                    'owner_id' => $commonRatePricing->owner_id,
                    'configured_value' => $value,
                    'resolved_value' => $value,
                ];
                return $value;
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Error getting common rate value for {$rateKey}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Evaluate conditions to determine if calculation should proceed.
     * 
     * @param array $inputs Input values for condition evaluation
     * @return bool True if all conditions are met
     */
    private function evaluateConditions(array $inputs): bool
    {
        if (empty($this->conditions)) {
            return true; // No conditions means always proceed
        }

        foreach ($this->conditions as $condition) {
            $field = $condition['field'];
            $operator = $condition['operator'] ?? '=';
            $expectedValue = $condition['value'];
            $actualValue = $inputs[$field] ?? null;

            $conditionMet = match ($operator) {
                '=', 'equals' => $actualValue == $expectedValue,
                '!=', 'not_equals' => $actualValue != $expectedValue,
                '>', 'greater_than' => is_numeric($actualValue) && is_numeric($expectedValue) && $actualValue > $expectedValue,
                '<', 'less_than' => is_numeric($actualValue) && is_numeric($expectedValue) && $actualValue < $expectedValue,
                '>=', 'greater_than_or_equal' => is_numeric($actualValue) && is_numeric($expectedValue) && $actualValue >= $expectedValue,
                '<=', 'less_than_or_equal' => is_numeric($actualValue) && is_numeric($expectedValue) && $actualValue <= $expectedValue,
                'in', 'contains' => is_array($expectedValue) && in_array($actualValue, $expectedValue),
                'not_in', 'not_contains' => is_array($expectedValue) && !in_array($actualValue, $expectedValue),
                'between' => is_array($expectedValue) && count($expectedValue) === 2 &&
                is_numeric($actualValue) &&
                $actualValue >= $expectedValue[0] &&
                $actualValue <= $expectedValue[1],
                'exists' => $actualValue !== null,
                'not_exists' => $actualValue === null,
                'empty' => empty($actualValue),
                'not_empty' => !empty($actualValue),
                'starts_with' => is_string($actualValue) && is_string($expectedValue) && str_starts_with($actualValue, $expectedValue),
                'ends_with' => is_string($actualValue) && is_string($expectedValue) && str_ends_with($actualValue, $expectedValue),
                'regex' => is_string($actualValue) && is_string($expectedValue) && preg_match($expectedValue, $actualValue),
                default => false,
            };

            if (!$conditionMet) {
                Log::debug("Condition not met: {$field} {$operator} " . json_encode($expectedValue) . " (actual: " . json_encode($actualValue) . ")");
                return false;
            }
        }

        return true;
    }

    /**
     * Safely evaluate a mathematical formula.
     * 
     * @param string $formula The formula to evaluate
     * @return float|null The result or null if evaluation fails
     */
    private function evaluateFormula(string $formula): ?float
    {
        try {
            // Clean and validate the formula
            $cleanFormula = trim($formula);

            // Allow arithmetic plus the explicitly supported min/max helpers.
            if (!preg_match('/^[0-9A-Za-z_+\-*\/(),.\\s]+$/', $cleanFormula)) {
                throw new \Exception("Formula contains invalid characters: {$cleanFormula}");
            }
            preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $cleanFormula, $identifiers);
            $unsupportedFunctions = array_diff(array_unique($identifiers[0] ?? []), ['max', 'min']);
            if ($unsupportedFunctions !== []) {
                throw new \Exception(
                    'Formula contains unsupported functions: ' . implode(', ', $unsupportedFunctions)
                );
            }

            // Additional security: prevent multiple consecutive operators
            if (preg_match('/[+\-*\/]{2,}/', $cleanFormula)) {
                throw new \Exception("Formula contains invalid operator sequences: {$cleanFormula}");
            }

            // Prevent division by zero patterns
            if (preg_match('/\/\s*0(\s|$|\))/', $cleanFormula)) {
                throw new \Exception("Formula contains division by zero: {$cleanFormula}");
            }

            // Use safer evaluation method
            $result = $this->safeEval($cleanFormula);

            return is_numeric($result) && is_finite($result) ? (float) $result : null;
        } catch (\Exception $e) {
            Log::error("Formula evaluation error: " . $e->getMessage() . " Formula: {$formula}");
            throw $e;
        }
    }

    /**
     * Safer alternative to eval() for mathematical expressions.
     *
     * @param string $expression Mathematical expression
     * @return float|null Result of evaluation
     */
    private function safeEval(string $expression): ?float
    {
        // For production use, consider using symfony/expression-language
        // For now, we'll use a controlled eval with additional safety measures

        // Remove all whitespace
        $expression = preg_replace('/\s+/', '', $expression);

        // Final validation: identifiers are limited to the two allowlisted
        // numeric helpers before the controlled evaluation below.
        if (!preg_match('/^[0-9A-Za-z_+\-*\/(),.]+$/', $expression)) {
            throw new \Exception("Expression failed final validation: {$expression}");
        }
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $expression, $identifiers);
        if (array_diff(array_unique($identifiers[0] ?? []), ['max', 'min']) !== []) {
            throw new \Exception("Expression contains an unsupported function: {$expression}");
        }

        // Check for balanced parentheses
        if (substr_count($expression, '(') !== substr_count($expression, ')')) {
            throw new \Exception("Unbalanced parentheses in expression: {$expression}");
        }

        // Evaluate using eval (in production, replace with a proper math parser)
        $result = @eval ("return {$expression};");

        if ($result === false || !is_numeric($result)) {
            throw new \Exception("Failed to evaluate expression: {$expression}");
        }

        return (float) $result;
    }

    /**
     * Apply KM range pricing rules and price adjustments.
     * 
     * Applies all applicable pricing rules and returns adjustment details
     * including discount information for frontend display.
     */
    private function applyPricingAdjustments(
        float $baseAmount,
        array $inputs,
        ?array $servicePackageInfo,
        ?array $districtInfo,
        array $kmCalculations,
        array $resolvedVariables = []
    ): array {
        $adjustments = [];
        $currentAmount = $baseAmount;
        $totalDiscount = 0.0;
        $totalIncrease = 0.0;

        $vehicleGroupId = $inputs['vehicle_group_id'] ?? null;
        $totalDistance = $kmCalculations['journey_distance'] ?? 0;
        $pricingStartDate = $this->resolvePricingAdjustmentDate($inputs, [
            'from_date',
            'pickup_date',
            'date',
        ]);
        $pricingEndDate = $this->resolvePricingAdjustmentDate($inputs, [
            'to_date',
            'dropoff_date',
            'return_date',
        ]) ?? $pricingStartDate;

        $applyComponent = function (string $component, float $amount) use (
            $vehicleGroupId,
            $pricingStartDate,
            $pricingEndDate,
            $inputs
        ): array {
            return PriceAdjustment::applyAdjustments(
                $amount,
                $this->service_type_id,
                $vehicleGroupId,
                $component,
                $pricingStartDate,
                $pricingEndDate,
                $inputs['owner_type'] ?? null,
                $inputs['owner_id'] ?? null,
                $inputs['pricing_context'] ?? 'public'
            );
        };

        $appendComponentResult = function (array $componentResult, string $component) use (
            &$adjustments,
            &$totalDiscount,
            &$totalIncrease
        ): void {
            foreach ($componentResult['adjustments_applied'] ?? [] as $adjustment) {
                $adjustments[] = [
                    'type' => 'price_adjustment',
                    'price_adjustment_id' => $adjustment['adjustment_info']['id'] ?? null,
                    'applies_to' => $component,
                    'name' => $adjustment['adjustment_info']['name'] ?? 'Price Adjustment',
                    'description' => $adjustment['adjustment_info']['description'] ?? null,
                    'amount' => $adjustment['adjustment_amount'] ?? 0,
                    'calculation' => $adjustment['calculation_details'] ?? null,
                    'is_cumulative' => $adjustment['adjustment_info']['is_cumulative'] ?? false,
                    'is_discount' => $adjustment['is_discount'] ?? false,
                    'discount_amount' => $adjustment['discount_amount'] ?? 0,
                    'adjustment_type' => $adjustment['adjustment_info']['adjustment_type'] ?? null,
                    'original_amount' => $adjustment['original_amount'] ?? null,
                    'final_amount' => $adjustment['final_amount'] ?? null,
                ];
            }

            $totalDiscount += (float) ($componentResult['total_discount'] ?? 0);
            $totalIncrease += (float) ($componentResult['total_increase'] ?? 0);
        };

        // A base-price rule adjusts the formula result before range and
        // booking-total rules. This keeps all three configured applies_to
        // values operational instead of silently ignoring two of them.
        $baseAdjustmentResult = $applyComponent('base_price', $currentAmount);
        $appendComponentResult($baseAdjustmentResult, 'base_price');
        $currentAmount = (float) ($baseAdjustmentResult['final_amount'] ?? $currentAmount);

        $totalKmAdjustment = 0;
        if ($vehicleGroupId && $totalDistance > 0) {
            $kmRangeResult = KmRangePricingRule::calculateBestPricing(
                $totalDistance,
                $currentAmount,
                $this->service_type_id,
                $vehicleGroupId,
                null,
                $inputs['owner_type'] ?? null,
                $inputs['owner_id'] ?? null
            );

            if (!empty($kmRangeResult['rules_applied'])) {
                foreach ($kmRangeResult['rules_applied'] as $rule) {
                    $adjustmentAmount = $rule['adjustment_amount'] ?? 0;
                    $adjustments[] = [
                        'type' => 'km_range_pricing',
                        'name' => $rule['rule_info']['name'] ?? 'KM Range Pricing',
                        'amount' => $adjustmentAmount,
                        'calculation' => $rule['calculation_details'] ?? null,
                        'is_discount' => $adjustmentAmount < 0,
                    ];
                    $totalKmAdjustment += $adjustmentAmount;
                }

                // Apply KM-range adjustment to the current amount so subsequent price adjustments are stacked on top
                $currentAmount += $totalKmAdjustment;

                // Add a summary entry for frontend convenience
                $adjustments[] = [
                    'type' => 'km_range_pricing_summary',
                    'name' => 'KM Range Adjustment',
                    'amount' => $totalKmAdjustment,
                    'calculation' => $kmRangeResult['calculation_summary'] ?? null,
                    'is_discount' => $totalKmAdjustment < 0,
                ];

                Log::info('KM range pricing applied', [
                    'vehicle_group_id' => $vehicleGroupId,
                    'distance' => $totalDistance,
                    'total_km_adjustment' => $totalKmAdjustment,
                    'current_amount' => $currentAmount,
                ]);
            }
        }

        $kmChargeAmount = $this->calculateResolvedKmChargeComponent($resolvedVariables);
        if ($kmChargeAmount > 0) {
            $kmAdjustmentResult = $applyComponent('km_charges', $kmChargeAmount);
            $appendComponentResult($kmAdjustmentResult, 'km_charges');
            $currentAmount += (float) ($kmAdjustmentResult['final_amount'] ?? $kmChargeAmount)
                - $kmChargeAmount;
        }

        $totalAdjustmentResult = $applyComponent('total_price', $currentAmount);
        $appendComponentResult($totalAdjustmentResult, 'total_price');
        $currentAmount = (float) ($totalAdjustmentResult['final_amount'] ?? $currentAmount);

        // Merge KM range totals into the final display totals
        $kmDiscount = $totalKmAdjustment < 0 ? abs($totalKmAdjustment) : 0;
        $kmIncrease = $totalKmAdjustment > 0 ? $totalKmAdjustment : 0;

        $totalDiscount += $kmDiscount;
        $totalIncrease += $kmIncrease;
        $hasDiscount = $totalDiscount > 0;
        $hasIncrease = $totalIncrease > 0;

        return [
            'final_amount' => $currentAmount,
            'original_amount' => $baseAmount,
            'adjustments' => $adjustments,
            'total_adjustment' => $currentAmount - $baseAmount,
            'total_discount' => $totalDiscount,
            'total_increase' => $totalIncrease,
            'has_discount' => $hasDiscount,
            'has_increase' => $hasIncrease,
            'savings_display' => $hasDiscount ? 'LKR ' . number_format(floor(max(0, $totalDiscount)), 0) : null,
            'discount_percentage' => $hasDiscount && $baseAmount > 0
                ? round(($totalDiscount / $baseAmount) * 100, 1)
                : 0,
        ];
    }

    private function calculateResolvedKmChargeComponent(array $resolvedVariables): float
    {
        $pairs = [
            ['extra_km', 'extra_km_rate'],
            ['total_distance', 'service_rate_per_km'],
            ['journey_distance', 'journey_rate_per_km'],
            ['actual_distance', 'distance_rate'],
            ['distance_km', 'rate_per_km'],
            ['delivery_distance', 'vehicle_delivery_rate_per_km'],
            ['pickup_distance', 'vehicle_pickup_rate_per_km'],
        ];
        $amount = 0.0;

        foreach ($pairs as [$quantityName, $rateName]) {
            if (
                !$this->formulaReferencesVariable($quantityName)
                || !$this->formulaReferencesVariable($rateName)
                || !is_numeric($resolvedVariables[$quantityName] ?? null)
                || !is_numeric($resolvedVariables[$rateName] ?? null)
            ) {
                continue;
            }

            $amount += max(0, (float) $resolvedVariables[$quantityName])
                * max(0, (float) $resolvedVariables[$rateName]);
        }

        return round($amount, 2);
    }

    private function formulaReferencesVariable(string $variableName): bool
    {
        return (bool) preg_match(
            '/(?<![A-Za-z0-9_])' . preg_quote($variableName, '/') . '(?![A-Za-z0-9_])/',
            (string) $this->formula
        );
    }

    private function resolvePricingAdjustmentDate(array $inputs, array $keys): ?Carbon
    {
        foreach ($keys as $key) {
            $value = $inputs[$key] ?? null;

            if (empty($value)) {
                continue;
            }

            if ($value instanceof Carbon) {
                return $value->copy();
            }

            try {
                return Carbon::parse($value);
            } catch (\Throwable $exception) {
                Log::warning('Failed to parse pricing adjustment date', [
                    'key' => $key,
                    'value' => $value,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Validate the persisted formula contract without resolving database rates.
     *
     * @return array<int, string>
     */
    public static function validateFormulaConfiguration(string $formula, array $variables): array
    {
        $errors = [];
        $variableNames = [];

        foreach ($variables as $variable) {
            $name = is_array($variable) ? trim((string) ($variable['name'] ?? '')) : '';
            if ($name === '') {
                continue;
            }

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                $errors[] = "Variable name '{$name}' must start with a letter or underscore and contain only letters, numbers, and underscores.";
                continue;
            }

            if (in_array($name, $variableNames, true)) {
                $errors[] = "Variable '{$name}' is declared more than once.";
                continue;
            }

            $variableNames[] = $name;
        }

        return array_values(array_unique(array_merge(
            $errors,
            self::validateFormulaAgainstVariableNames($formula, $variableNames)
        )));
    }

    /**
     * @param array<int, string> $variableNames
     * @return array<int, string>
     */
    private static function validateFormulaAgainstVariableNames(string $formula, array $variableNames): array
    {
        $compactFormula = preg_replace('/\s+/', '', trim($formula)) ?? '';
        if ($compactFormula === '') {
            return ['Formula is required.'];
        }

        preg_match_all(
            '/\{[A-Za-z_][A-Za-z0-9_]*\}|[A-Za-z_][A-Za-z0-9_]*|\d+(?:\.\d+)?|[+\-*\/(),]/',
            $compactFormula,
            $matches
        );
        $originalTokens = $matches[0] ?? [];

        if (implode('', $originalTokens) !== $compactFormula) {
            return ['Formula may contain only declared variables, numbers, min/max, commas, +, -, *, /, and parentheses.'];
        }

        $referencedVariables = collect($originalTokens)
            ->filter(fn (string $token): bool => preg_match('/^(?:\{[A-Za-z_][A-Za-z0-9_]*\}|[A-Za-z_][A-Za-z0-9_]*)$/', $token) === 1)
            ->map(fn (string $token): string => trim($token, '{}'))
            ->reject(fn (string $name): bool => in_array($name, ['max', 'min'], true))
            ->values()
            ->all();

        $syntaxFormula = self::normalizeSupportedFunctionsForValidation($compactFormula);
        if ($syntaxFormula === null) {
            return ['Formula functions must use max(value, value) or min(value, value).'];
        }
        preg_match_all(
            '/\{[A-Za-z_][A-Za-z0-9_]*\}|[A-Za-z_][A-Za-z0-9_]*|\d+(?:\.\d+)?|[+\-*\/()]/',
            $syntaxFormula,
            $syntaxMatches
        );
        $tokens = $syntaxMatches[0] ?? [];

        $errors = [];
        $expectsValue = true;
        $parenthesisDepth = 0;

        foreach ($tokens as $index => $token) {
            $isNumber = preg_match('/^\d+(?:\.\d+)?$/', $token) === 1;
            $isVariable = preg_match('/^(?:\{[A-Za-z_][A-Za-z0-9_]*\}|[A-Za-z_][A-Za-z0-9_]*)$/', $token) === 1;

            if ($expectsValue) {
                if ($isNumber || $isVariable) {
                    $expectsValue = false;
                } elseif ($token === '(') {
                    $parenthesisDepth++;
                } elseif (($token === '+' || $token === '-') && ($index === 0 || $tokens[$index - 1] === '(')) {
                    continue;
                } else {
                    $errors[] = "Formula has an unexpected '{$token}'.";
                    break;
                }
            } elseif (in_array($token, ['+', '-', '*', '/'], true)) {
                $expectsValue = true;
            } elseif ($token === ')' && $parenthesisDepth > 0) {
                $parenthesisDepth--;
            } else {
                $errors[] = "Formula has an unexpected '{$token}'.";
                break;
            }
        }

        if ($errors === [] && ($expectsValue || $parenthesisDepth !== 0)) {
            $errors[] = $parenthesisDepth !== 0
                ? 'Formula parentheses are not balanced.'
                : 'Formula must end with a number or declared variable.';
        }

        $unknownVariables = array_values(array_unique(array_diff($referencedVariables, $variableNames)));
        if ($unknownVariables !== []) {
            $errors[] = 'Formula references undeclared variables: ' . implode(', ', $unknownVariables) . '.';
        }

        return array_values(array_unique($errors));
    }

    private static function normalizeSupportedFunctionsForValidation(string $formula): ?string
    {
        $normalized = $formula;

        do {
            $previous = $normalized;
            $invalid = false;
            $normalized = preg_replace_callback(
                '/\b(?:max|min)\(([^()]*)\)/',
                static function (array $match) use (&$invalid): string {
                    $arguments = array_map('trim', explode(',', $match[1]));
                    if (count($arguments) !== 2 || in_array('', $arguments, true)) {
                        $invalid = true;
                        return $match[0];
                    }

                    return '0';
                },
                $normalized
            ) ?? $normalized;

            if ($invalid) {
                return null;
            }
        } while ($normalized !== $previous);

        return preg_match('/\b(?:max|min)\s*\(/', $normalized) === 1
            ? null
            : $normalized;
    }

    /**
     * Validate that the formula is syntactically correct.
     */
    public function validateFormula(): bool
    {
        try {
            if (self::validateFormulaConfiguration($this->formula, $this->variables ?? []) !== []) {
                return false;
            }

            $sampleInputs = $this->generateSampleInputs();
            $result = $this->calculatePrice($sampleInputs);

            return isset($result['total_amount']) &&
                is_numeric($result['total_amount']) &&
                is_finite($result['total_amount']);
        } catch (\Exception $e) {
            Log::warning("Formula validation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate sample inputs for formula validation.
     */
    private function generateSampleInputs(): array
    {
        $sampleInputs = [
            'vehicle_group_id' => 'sample-group-id',
            'duration_hours' => 24,
            'hours' => 24,
            'pickup_distance' => 10.5,
            'return_distance' => 8.3,
            'distance' => 50,
        ];

        foreach ($this->variables ?? [] as $variable) {
            $varName = $variable['name'];
            $varType = $variable['type'] ?? 'number';

            // Provide realistic sample values based on type
            $sampleInputs[$varName] = match ($varType) {
                'slab_rate' => 1000,
                'common_rate' => 50,
                'duration' => 24,
                'distance' => 100,
                'number' => 100,
                'fixed_value' => $variable['default_value'] ?? 100,
                'boolean' => true,
                'string' => 'sample',
                'date' => time(),
                default => 100,
            };
        }

        return $sampleInputs;
    }

    private function applyOwnerScope($query, ?string $ownerType, ?string $ownerId): void
    {
        if ($ownerType && $ownerId) {
            $query->where(function ($scopeQuery) use ($ownerType, $ownerId) {
                $scopeQuery->where(function ($scoped) use ($ownerType, $ownerId) {
                    $scoped->where('owner_type', $ownerType)->where('owner_id', $ownerId);
                })->orWhereNull('owner_type');
            });

            return;
        }

        $query->whereNull('owner_type')->whereNull('owner_id');
    }

    private function applyOwnerPriorityOrder($query, ?string $ownerType, ?string $ownerId): void
    {
        if ($ownerType && $ownerId) {
            $query->orderByRaw(
                'CASE WHEN owner_type = ? AND owner_id = ? THEN 0 ELSE 1 END',
                [$ownerType, $ownerId]
            );

            return;
        }

        $query->orderByRaw('1');
    }

    /**
     * Get supported variable types.
     *
     * @return array Array of supported variable types with descriptions
     */
    public static function getSupportedVariableTypes(): array
    {
        return [
            'slab_rate' => [
                'label' => 'Slab Rate',
                'description' => 'Base rate from vehicle group pricing slab',
                'requires_vehicle_group' => true,
                'requires_duration' => true
            ],
            'common_rate' => [
                'label' => 'Common Rate',
                'description' => 'Additional rate from common rate definitions',
                'requires_vehicle_group' => true
            ],
            'duration' => [
                'label' => 'Duration',
                'description' => 'Time-based value (hours, days, etc.)',
                'requires_input' => true
            ],
            'distance' => [
                'label' => 'Distance',
                'description' => 'Distance-based value (km, miles, etc.)',
                'requires_input' => true
            ],
            'number' => [
                'label' => 'Number',
                'description' => 'Generic numeric value',
                'requires_input' => true
            ],
            'fixed_value' => [
                'label' => 'Fixed Value',
                'description' => 'Constant value defined in the variable',
                'requires_input' => false
            ],
            'calculated' => [
                'label' => 'Calculated',
                'description' => 'Value calculated by custom method',
                'requires_input' => false
            ]
        ];
    }

    /**
     * Get supported condition operators.
     *
     * @return array Array of supported operators with descriptions
     */
    public static function getSupportedConditionOperators(): array
    {
        return [
            'equals' => 'Equals (=)',
            'not_equals' => 'Not equals (!=)',
            'greater_than' => 'Greater than (>)',
            'less_than' => 'Less than (<)',
            'greater_than_or_equal' => 'Greater than or equal (>=)',
            'less_than_or_equal' => 'Less than or equal (<=)',
        ];
    }

    /**
     * Get human-readable formula description.
     * 
     * @return string Description of the formula
     */
    public function getFormulaDescription(): string
    {
        $description = $this->formula;

        // Replace variable placeholders with their descriptions
        foreach ($this->variables ?? [] as $variable) {
            $varName = $variable['name'];
            $varDescription = $variable['description'] ?? $varName;
            $description = str_replace("{{$varName}}", "[{$varDescription}]", $description);
        }

        return $description;
    }

    /**
     * Build a deterministic, configuration-driven example without requiring a
     * booking. This is returned with every definition so users can see the
     * formula with representative values even before opening the tester.
     */
    public function getCalculationExample(): array
    {
        $inputs = [];
        foreach ($this->variables ?? [] as $variable) {
            $name = (string) ($variable['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $configured = $variable['default_value'] ?? null;
            $inputs[$name] = is_numeric($configured) && (float) $configured !== 0.0
                ? (float) $configured
                : $this->exampleValueForVariable($name);
        }

        $substituted = $this->formula;
        foreach ($inputs as $name => $value) {
            $substituted = str_replace('{' . $name . '}', (string) $value, $substituted);
            $substituted = preg_replace(
                '/\b' . preg_quote($name, '/') . '\b/',
                (string) $value,
                $substituted
            ) ?? $substituted;
        }

        return [
            'inputs' => $inputs,
            'substituted_formula' => $substituted,
            'result' => $this->evaluateFormulaWithVariables($this->formula, $inputs),
        ];
    }

    private function exampleValueForVariable(string $name): float
    {
        return match (true) {
            str_contains($name, 'percentage') => 0.1,
            str_contains($name, 'minutes') => 90.0,
            str_contains($name, 'hours') => 2.0,
            str_contains($name, 'days') => 1.0,
            str_contains($name, 'distance'), str_contains($name, '_km') => 10.0,
            str_contains($name, 'stops') => 2.0,
            str_contains($name, 'rate'), str_contains($name, 'charge'), str_contains($name, 'allowance') => 100.0,
            default => 1.0,
        };
    }

    /**
     * Get calculation breakdown for transparency.
     * 
     * @param array $inputs Input values for calculation
     * @return array Detailed breakdown of the calculation
     */
    public function getCalculationBreakdown(array $inputs): array
    {
        $breakdown = [
            'formula' => $this->formula,
            'variables' => [],
            'slab_details' => null,
            'common_rates' => [],
            'total' => null,
            'errors' => []
        ];

        try {
            // Process each variable
            foreach ($this->variables ?? [] as $variable) {
                $varName = $variable['name'];
                $varType = $variable['type'] ?? 'number';
                $defaultValue = $variable['default_value'] ?? null;

                $value = $inputs[$varName] ?? $defaultValue;

                // Special handling for slab_rate
                if ($varName === 'slab_rate') {
                    $slabDetails = $this->getSlabRateBreakdown($inputs);
                    $breakdown['slab_details'] = $slabDetails;
                    $value = $slabDetails['calculated_rate'] ?? 0;
                }

                // Special handling for common rates
                if (str_starts_with($varName, 'common_rate_')) {
                    $rateKey = substr($varName, 12);
                    $commonRateDetails = $this->getCommonRateBreakdown($rateKey, $inputs);
                    $breakdown['common_rates'][$rateKey] = $commonRateDetails;
                    $value = $commonRateDetails['rate'] ?? 0;
                }

                $breakdown['variables'][$varName] = [
                    'name' => $varName,
                    'type' => $varType,
                    'input_value' => $inputs[$varName] ?? null,
                    'default_value' => $defaultValue,
                    'final_value' => $value,
                    'description' => $variable['description'] ?? null
                ];
            }

            // Calculate final total
            $breakdown['total'] = $this->calculatePrice($inputs);
        } catch (\Exception $e) {
            $breakdown['errors'][] = $e->getMessage();
        }

        return $breakdown;
    }

    /**
     * Get detailed slab rate breakdown.
     */
    private function getSlabRateBreakdown(array $inputs): array
    {
        $inputs = $this->normalizeDurationUnits($inputs);
        $breakdown = [
            'vehicle_group_id' => $inputs['vehicle_group_id'] ?? null,
            'duration_hours' => $inputs['duration_hours'] ?? $inputs['hours'] ?? 0,
            'duration_minutes' => $inputs['duration_minutes'] ?? 0,
            'duration_days' => $inputs['duration_days'] ?? $inputs['days'] ?? 0,
            'slab_definition' => null,
            'pricing_details' => null,
            'calculated_rate' => 0
        ];

        try {
            $vehicleGroupId = $breakdown['vehicle_group_id'];
            $ownerType = $inputs['owner_type'] ?? null;
            $ownerId = $inputs['owner_id'] ?? null;
            $slabInformation = $this->getSlabInformation($inputs);

            if (!$vehicleGroupId || !$slabInformation) {
                return $breakdown;
            }

            $slabDefinition = $slabInformation['slab_definition'];

            if ($slabDefinition) {
                $breakdown['slab_definition'] = [
                    'id' => $slabDefinition->id,
                    'name' => $slabDefinition->name,
                    'min_hours' => $slabDefinition->min_hours,
                    'max_hours' => $slabDefinition->max_hours,
                    'min_minutes' => $slabDefinition->min_minutes,
                    'max_minutes' => $slabDefinition->max_minutes,
                    'min_days' => $slabDefinition->min_days,
                    'max_days' => $slabDefinition->max_days,
                    'type' => $slabDefinition->type
                ];

                // Get the vehicle group pricing
                $vehicleGroupPricing = VehicleGroupPricing::where('vehicle_group_id', $vehicleGroupId)
                    ->where('slab_definition_id', $slabDefinition->id)
                    ->where('is_active', true)
                    ->forOwner($ownerType, $ownerId)
                    ->tap(fn ($query) => $this->applyOwnerPriorityOrder($query, $ownerType, $ownerId))
                    ->orderByDesc('priority')
                    ->first();

                if ($vehicleGroupPricing) {
                    $breakdown['pricing_details'] = [
                        'base_rate' => (float) $vehicleGroupPricing->rate,
                        'rate_type' => $vehicleGroupPricing->rate_type,
                        'minimum_charge' => $vehicleGroupPricing->minimum_charge ? (float) $vehicleGroupPricing->minimum_charge : null,
                        'includes_fuel' => $vehicleGroupPricing->includes_fuel,
                        'includes_driver' => $vehicleGroupPricing->includes_driver
                    ];

                    $breakdown['calculated_rate'] = (float) $vehicleGroupPricing->rate;
                }
            }
        } catch (\Exception $e) {
            $breakdown['error'] = $e->getMessage();
        }

        return $breakdown;
    }

    /**
     * Get detailed common rate breakdown.
     * 
     * @param string $rateKey The common rate identifier
     * @param array $inputs Input values
     * @return array Common rate details
     */
    private function getCommonRateBreakdown(string $rateKey, array $inputs): array
    {
        $breakdown = [
            'rate_key' => $rateKey,
            'vehicle_group_id' => $inputs['vehicle_group_id'] ?? null,
            'rate' => null,
            'source' => 'missing_vehicle_group_value'
        ];

        try {
            $vehicleGroupId = $breakdown['vehicle_group_id'];

            if (!$vehicleGroupId) {
                return $breakdown;
            }

            $ownerType = $inputs['owner_type'] ?? null;
            $ownerId = $inputs['owner_id'] ?? null;

            // Check vehicle group specific pricing first
            $commonRatePricing = VehicleGroupCommonRatePricing::whereHas('commonRateDefinition', function ($query) use ($rateKey, $ownerType, $ownerId) {
                $query->where(function ($rateQuery) use ($rateKey) {
                    $rateQuery->where('code', $rateKey)->orWhere('name', $rateKey);
                })
                    ->where('service_type_id', $this->service_type_id)
                    ->where('is_active', true);
                $this->applyOwnerScope($query, $ownerType, $ownerId);
            })
                ->where('vehicle_group_id', $vehicleGroupId)
                ->where('is_active', true)
                ->when($ownerType && $ownerId, function ($query) use ($ownerType, $ownerId) {
                    $this->applyOwnerScope($query, $ownerType, $ownerId);
                }, fn ($query) => $query->whereNull('owner_type')->whereNull('owner_id'))
                ->tap(fn ($query) => $this->applyOwnerPriorityOrder($query, $ownerType, $ownerId))
                ->orderBy('priority', 'desc')
                ->first();

            if ($commonRatePricing) {
                $breakdown['rate'] = (float) ($commonRatePricing->value ?? $commonRatePricing->rate);
                $breakdown['source'] = 'vehicle_group_specific';
                return $breakdown;
            }
        } catch (\Exception $e) {
            $breakdown['error'] = $e->getMessage();
        }

        return $breakdown;
    }
}
