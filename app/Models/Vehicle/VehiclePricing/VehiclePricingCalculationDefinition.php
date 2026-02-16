<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\Service\ServiceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Models\Vehicle\VehiclePricing\PriceAdjustment;
use App\Models\Vehicle\VehiclePricing\KmRangePricingRule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Vehicle Pricing Calculation Definition Model
 */
class VehiclePricingCalculationDefinition extends Model
{
    use HasFactory, SoftDeletes;

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
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'variables' => 'array',
        'conditions' => 'array',
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
                // Structured error response for missing variables
                Log::warning("Missing variables for calculation {$this->id}: " . $ex->getMessage());
                return [
                    'total_amount' => 0,
                    'breakdown' => [],
                    'slab_info' => $slabInfo,
                    'km_calculations' => $kmCalculations,
                    'conditions_met' => true,
                    'definition_id' => $this->id,
                    'variables_used' => [],
                    'conditions_evaluated' => $metadata['conditions_evaluated'],
                    'missing_variables' => array_values(array_filter(array_map(fn($v) => $v['name'] ?? null, $this->variables ?? []), function ($name) use ($inputs) {
                        return $name !== null && !array_key_exists($name, $inputs);
                    }))
                ];
            }

            $metadata['variables_used'] = array_keys($resolvedVariables);

            $totalAmount = $this->evaluateFormulaWithVariables($this->formula, $resolvedVariables) ?? 0.0;
            $totalAmountWithoutCustomizations = $this->evaluateFormulaWithVariables($this->formula, $resolvedVariablesWithoutCustomizations) ?? 0.0;

            // Apply price adjustments and KM range pricing rules after base calculation
            $adjustmentResults = $this->applyPricingAdjustments(
                $totalAmount,
                $inputs,
                $servicePackageInfo,
                $districtInfo,
                $kmCalculations
            );

            $finalAmount = $adjustmentResults['final_amount'];
            $finalAmountWithoutCustomizations = $this->applyPricingAdjustments(
                $totalAmountWithoutCustomizations,
                $inputs,
                $servicePackageInfo,
                $districtInfo,
                $kmCalculations
            )['final_amount'];

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
     * Build detailed calculation breakdown
     */
    private function buildCalculationBreakdown(array $resolvedVariables, float $totalAmount, float $totalAmountWithoutCustomizations, ?array $slabInfo, array $kmCalculations, array $adjustmentResults = []): array
    {
        $breakdown = [];

        // Base rate breakdown
        if (isset($resolvedVariables['slab_rate']) && $resolvedVariables['slab_rate'] > 0) {
            $breakdown[] = [
                'component' => 'base_rate',
                'description' => 'Base service rate',
                'amount' => $resolvedVariables['slab_rate'],
                'calculation' => $slabInfo ? "Based on {$slabInfo['type']} for " . ($slabInfo['duration_days'] ?? $slabInfo['duration_hours']) . ($slabInfo['duration_days'] ? ' days' : 'h') : 'Base rate',
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
            'stop_charge' => 'Stop charges',
            'emergency_base_rate' => 'Emergency base fee',
            'overtime_rate_per_hour' => 'Overtime charges',
        ];

        foreach ($otherCharges as $varName => $description) {
            if (isset($resolvedVariables[$varName]) && $resolvedVariables[$varName] > 0) {
                $amount = $resolvedVariables[$varName];

                // Handle per-unit charges
                if (str_contains($varName, '_per_hour') && isset($resolvedVariables['waiting_hours'])) {
                    $hours = $resolvedVariables['waiting_hours'];
                    $amount = $hours * $resolvedVariables[$varName];
                    $calculation = "{$hours} hours × LKR {$resolvedVariables[$varName]}";
                } elseif (str_contains($varName, '_per_hour') && isset($resolvedVariables['overtime_hours'])) {
                    $hours = $resolvedVariables['overtime_hours'];
                    $amount = $hours * $resolvedVariables[$varName];
                    $calculation = "{$hours} hours × LKR {$resolvedVariables[$varName]}";
                } elseif ($varName === 'stop_charge' && isset($resolvedVariables['stops'])) {
                    $stops = $resolvedVariables['stops'] ?? $resolvedVariables['additional_stops'] ?? 0;
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

        return [
            'total_amount' => $totalAmount,
            'subtotal' => $totalAmount,
            'total_amount_without_customizations' => $totalAmountWithoutCustomizations,
            'breakdown' => $breakdown,
            'slab_info' => $slabInfo,
            'km_calculations' => $kmCalculations,
            'conditions_met' => true,
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
        $durationDays = $inputs['duration_days'] ?? $inputs['days'] ?? 0;

        if (!$vehicleGroupId) {
            return null;
        }

        // Convert days to hours if needed
        if ($durationDays > 0 && $durationHours == 0) {
            $durationHours = $durationDays * 24;
        }

        $slabDefinition = VehiclePricingSlabDefinition::where('service_type_id', $this->service_type_id)
            ->where('is_active', true)
            ->where(function ($query) use ($durationHours, $durationDays) {
                $query->when($durationDays > 0, function ($q) use ($durationDays) {
                    return $q->where('min_days', '<=', $durationDays)
                        ->where(function ($subQ) use ($durationDays) {
                            $subQ->whereNull('max_days')
                                ->orWhere('max_days', '>=', $durationDays);
                        });
                })->when($durationHours > 0 && $durationDays == 0, function ($q) use ($durationHours) {
                    return $q->where('min_hours', '<=', $durationHours)
                        ->where(function ($subQ) use ($durationHours) {
                            $subQ->whereNull('max_hours')
                                ->orWhere('max_hours', '>=', $durationHours);
                        });
                });
            })
            ->orderBy('min_hours')
            ->orderBy('min_days')
            ->first();

        if (!$slabDefinition) {
            return null;
        }

        return [
            'slab_definition' => $slabDefinition,
            'duration_hours' => $durationHours,
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
        $result = [
            'journey_distance' => $inputs['journey_distance'] ?? $inputs['total_distance'] ?? 0,
            'allowed_km' => 0,
            'extra_km' => 0,
            'daily_overage' => 0,
            'package_overage' => 0,
            'calculation_type' => 'none',
            'calendar_days' => 0,
            'effective_days' => 0
        ];

        Log::debug('Calculating KM overages - Initial', [
            'journey_distance' => $result['journey_distance'],
            'result' => $result,
            'slab_info' => $slabInfo,
            'service_package_info' => $servicePackageInfo,
            'inputs' => $inputs
        ]);
        if (!$slabInfo) {
            return $result;
        }

        Log::debug('Calculating KM overages', [
            'journey_distance' => $result['journey_distance'],
            'slab_info' => $slabInfo,
            'service_package_info' => $servicePackageInfo,
            'inputs' => $inputs
        ]);
        $actualKm = (float) $result['journey_distance'];

        // Enhanced daily calculation for calendar days
        $calendarDays = $this->calculateCalendarDays($inputs);
        $result['calendar_days'] = $calendarDays;
        $result['effective_days'] = max(1, $calendarDays); // Minimum 1 day


        if ($servicePackageInfo && $servicePackageInfo['max_km_per_day']) {
            $result['calculation_type'] = 'daily';
            $result['allowed_km'] = $servicePackageInfo['max_km_per_day'] * $result['effective_days'];
            $result['extra_km'] = max(0, $actualKm - $result['allowed_km']);
            $result['package_overage'] = $result['extra_km'];
        } elseif ($servicePackageInfo && $servicePackageInfo['max_km_per_package']) {
            $result['calculation_type'] = 'package';
            $result['allowed_km'] = $servicePackageInfo['max_km_per_package'];
            $result['extra_km'] = max(0, $actualKm - $result['allowed_km']);
            $result['package_overage'] = $result['extra_km'];
        } elseif ($slabInfo['max_km_per_package'] && in_array($slabInfo['type'], ['flat_rate', 'package'])) {
            // Package-based limit (e.g., wedding packages, airport transfers)
            $result['calculation_type'] = 'package';
            $result['allowed_km'] = $slabInfo['max_km_per_package'];
            $result['extra_km'] = max(0, $actualKm - $result['allowed_km']);
            $result['package_overage'] = $result['extra_km'];
        } elseif ($slabInfo['max_km_per_day'] && $result['effective_days'] > 0) {
            // Daily-based limit (e.g., rental services, point to point, ride now)
            // For multi-day bookings: total allowance = max km × number of calendar days
            $result['calculation_type'] = 'daily';
            $result['allowed_km'] = $slabInfo['max_km_per_day'] * $result['effective_days'];
            $result['extra_km'] = max(0, $actualKm - $result['allowed_km']);
            $result['daily_overage'] = $result['extra_km'];
        } else {
            // No limits (e.g., KM-based services like point-to-point transfers)
            $result['calculation_type'] = 'unlimited';
            $result['allowed_km'] = $actualKm; // All KM is billable
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

        try {
            // Parse dates and times with proper timezone handling
            $startDateTime = Carbon::parse($fromDate . ' ' . $fromTime);
            $endDateTime = Carbon::parse($toDate . ' ' . $toTime);

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

    /**
     * Resolve all variables for calculation strictly as per definition with Service Package support.
     */
    private function resolveAllVariables(array $inputs, ?array $slabInfo, array $kmCalculations, ?array $appliedCustomizations, ?array $servicePackageInfo = null, ?array $districtInfo = null): array
    {
        $resolvedVariables = [];
        $missingVariables = [];

        foreach ($this->variables ?? [] as $variable) {

            $varName = $variable['name'];
            $varType = $variable['type'] ?? 'number';
            $isRequired = $variable['is_required'] ?? true;
            $defaultValue = $variable['default_value'] ?? null;
            // Definition-driven variables only

            if ($varName === 'extra_km') {
                $value = $kmCalculations['extra_km'] ?? 0;
            } elseif ($varName === 'allowed_km') {
                $value = $kmCalculations['allowed_km'] ?? 0;
            } elseif ($varName === 'journey_distance') {
                // journey_distance comes from kmCalculations first, then inputs
                $value = $kmCalculations['journey_distance'] ?? ($inputs['journey_distance'] ?? $defaultValue);
            } elseif ($varName === 'total_distance') {
                // total_distance should come from inputs (includes pickup+journey+delivery), NOT kmCalculations
                $value = $inputs['total_distance'] ?? ($kmCalculations['total_distance'] ?? $defaultValue);
            } else {
                $value = $this->resolveVariable($varName, $varType, $inputs, $defaultValue, $slabInfo, $appliedCustomizations, $servicePackageInfo, $districtInfo);
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
            // Ensure all variable values are numeric (fallback to 0 if not)
            $numericVars = [];
            foreach ($variables as $k => $v) {
                $numericVars[$k] = is_numeric($v) ? $v + 0 : 0; // +0 casts to int/float
            }

            // Replace {var} or bare var names as whole identifiers only (no partials)
            $evaluableFormula = preg_replace_callback(
                '/\{([A-Za-z_][A-Za-z0-9_]*)\}|(?<![A-Za-z0-9_])([A-Za-z_][A-Za-z0-9_]*)(?![A-Za-z0-9_])/',
                function ($m) use ($numericVars) {
                    $name = $m[1] !== '' ? $m[1] : $m[2];
                    $val = $numericVars[$name] ?? 0;
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


    private function getSlabRateValue(array $inputs, ?array $slabInfo = null, ?array $appliedCustomizations = null, ?array $servicePackageInfo = null, ?array $districtInfo = null): float
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
                return $customSlabBase ?? 0;
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
                    return $customSlabBase;
                }

                $slabDefinition = VehiclePricingSlabDefinition::where('service_type_id', $this->service_type_id)
                    ->where('is_active', true)
                    ->where(function ($query) use ($durationHours) {
                        $query->where('min_hours', '<=', $durationHours)
                            ->where(function ($q) use ($durationHours) {
                                $q->whereNull('max_hours')
                                    ->orWhere('max_hours', '>=', $durationHours);
                            });
                    })
                    ->orderBy('min_hours')
                    ->first();

                if (!$slabDefinition) {
                    Log::warning("No slab definition found for service type {$this->service_type_id} and duration {$durationHours}h");
                    return $customSlabBase ?? 0;
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
                    ->first()
                : null;

            if (!$vehicleGroupPricing) {
                // If no pricing row but we *do* have a custom base rate, treat it as a flat amount
                if ($customSlabBase !== null) {
                    return $customSlabBase;
                }
                return 0;
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

            return $baseRate;
        } catch (\Exception $e) {
            Log::error("Error getting slab rate value: " . $e->getMessage(), [
                'service_type_id' => $this->service_type_id,
                'inputs' => $inputs
            ]);
            return 0;
        }
    }

    /**
     * Get common rate value from database with fallback defaults.
     * Enhanced to provide default rates for delivery and pickup if not configured.
     * 
     * @param string $rateKey The common rate identifier
     * @param array $inputs Input values containing vehicle_group_id
     * @return float The common rate value
     */
    private function getCommonRateValue(string $rateKey, array $inputs): float
    {
        try {
            $vehicleGroupId = $inputs['vehicle_group_id'] ?? null;

            if (!$vehicleGroupId) {
                Log::warning("No vehicle_group_id provided for common rate lookup: {$rateKey}");
                return 0;
            }

            // Get vehicle group specific common rate pricing first
            $commonRatePricing = VehicleGroupCommonRatePricing::whereHas('commonRateDefinition', function ($query) use ($rateKey) {
                $query->where(function ($q) use ($rateKey) {
                    $q->where('code', $rateKey)
                        ->orWhere('name', $rateKey);
                })
                    ->where('service_type_id', $this->service_type_id)
                    ->where('is_active', true);
            })
                ->where('vehicle_group_id', $vehicleGroupId)
                ->where('is_active', true)
                ->first();

            if ($commonRatePricing && isset($commonRatePricing->value)) {
                return (float) $commonRatePricing->value;
            } elseif ($commonRatePricing && isset($commonRatePricing->rate)) {
                return (float) $commonRatePricing->rate;
            }

            // Fallback: Try to get a default rate from the common rate definition itself
            $commonRateDefinition = VehiclePricingCommonRateDefinition::where(function ($q) use ($rateKey) {
                $q->where('code', $rateKey)
                    ->orWhere('name', $rateKey);
            })
                ->where('service_type_id', $this->service_type_id)
                ->where('is_active', true)
                ->first();

            if ($commonRateDefinition) {
                return (float) $commonRateDefinition->rate;
            }

            return 0;
        } catch (\Exception $e) {
            Log::error("Error getting common rate value for {$rateKey}: " . $e->getMessage());
            return 0;
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

            // Allow numbers, basic math operators, parentheses, and dots for decimals
            if (!preg_match('/^[0-9+\-*\/().\\s]+$/', $cleanFormula)) {
                throw new \Exception("Formula contains invalid characters: {$cleanFormula}");
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

        // Final validation: only allow safe characters
        if (!preg_match('/^[0-9+\-*\/().]+$/', $expression)) {
            throw new \Exception("Expression failed final validation: {$expression}");
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
        array $kmCalculations
    ): array {
        $adjustments = [];
        $currentAmount = $baseAmount;

        $vehicleGroupId = $inputs['vehicle_group_id'] ?? null;
        $totalDistance = $kmCalculations['journey_distance'] ?? 0;

        $totalKmAdjustment = 0;
        if ($vehicleGroupId && $totalDistance > 0) {
            $kmRangeResult = KmRangePricingRule::calculateBestPricing(
                $totalDistance,
                $baseAmount,
                $this->service_type_id,
                $vehicleGroupId
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

        $priceAdjustmentResult = PriceAdjustment::applyAdjustments(
            $currentAmount,
            $this->service_type_id,
            $vehicleGroupId,
            'total_price'
        );

        if (!empty($priceAdjustmentResult['adjustments_applied'])) {
            foreach ($priceAdjustmentResult['adjustments_applied'] as $adjustment) {
                $adjustments[] = [
                    'type' => 'price_adjustment',
                    'name' => $adjustment['adjustment_info']['name'] ?? 'Price Adjustment',
                    'description' => $adjustment['adjustment_info']['description'] ?? null,
                    'amount' => $adjustment['adjustment_amount'] ?? 0,
                    'calculation' => $adjustment['calculation_details'] ?? null,
                    'is_cumulative' => $adjustment['adjustment_info']['is_cumulative'] ?? false,
                    'is_discount' => $adjustment['is_discount'] ?? false,
                    'discount_amount' => $adjustment['discount_amount'] ?? 0,
                    'adjustment_type' => $adjustment['adjustment_info']['adjustment_type'] ?? null,
                ];
            }
            $currentAmount = $priceAdjustmentResult['final_amount'];
        }

        // Calculate totals for frontend display
        $totalDiscount = $priceAdjustmentResult['total_discount'] ?? 0;
        $totalIncrease = $priceAdjustmentResult['total_increase'] ?? 0;
        $hasDiscount = $priceAdjustmentResult['has_discount'] ?? false;
        $hasIncrease = $priceAdjustmentResult['has_increase'] ?? false;

        // Merge KM range totals into the final display totals
        $kmDiscount = $totalKmAdjustment < 0 ? abs($totalKmAdjustment) : 0;
        $kmIncrease = $totalKmAdjustment > 0 ? $totalKmAdjustment : 0;

        $totalDiscount += $kmDiscount;
        $totalIncrease += $kmIncrease;
        $hasDiscount = $hasDiscount || $kmDiscount > 0;
        $hasIncrease = $hasIncrease || $kmIncrease > 0;

        return [
            'final_amount' => $currentAmount,
            'original_amount' => $baseAmount,
            'adjustments' => $adjustments,
            'total_adjustment' => $currentAmount - $baseAmount,
            'total_discount' => $totalDiscount,
            'total_increase' => $totalIncrease,
            'has_discount' => $hasDiscount,
            'has_increase' => $hasIncrease,
            'savings_display' => $hasDiscount ? 'LKR ' . number_format($totalDiscount, 2) : null,
            'discount_percentage' => $hasDiscount && $baseAmount > 0
                ? round(($totalDiscount / $baseAmount) * 100, 1)
                : 0,
        ];
    }

    /**
     * Validate that the formula is syntactically correct.
     */
    public function validateFormula(): bool
    {
        try {
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
            'between' => 'Between (inclusive)',
            'contains' => 'Contains (in array)',
            'not_contains' => 'Does not contain (not in array)',
            'exists' => 'Field exists (not null)',
            'not_exists' => 'Field does not exist (is null)',
            'empty' => 'Field is empty',
            'not_empty' => 'Field is not empty',
            'starts_with' => 'Starts with (string)',
            'ends_with' => 'Ends with (string)',
            'regex' => 'Matches regex pattern'
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
        $breakdown = [
            'vehicle_group_id' => $inputs['vehicle_group_id'] ?? null,
            'duration_hours' => $inputs['duration_hours'] ?? $inputs['hours'] ?? 0,
            'slab_definition' => null,
            'pricing_details' => null,
            'calculated_rate' => 0
        ];

        try {
            $vehicleGroupId = $breakdown['vehicle_group_id'];
            $durationHours = $breakdown['duration_hours'];

            if (!$vehicleGroupId || !$durationHours) {
                return $breakdown;
            }

            $slabDefinition = VehiclePricingSlabDefinition::where('service_type_id', $this->service_type_id)
                ->where('is_active', true)
                ->where(function ($query) use ($durationHours) {
                    $query->where('min_hours', '<=', $durationHours)
                        ->where(function ($q) use ($durationHours) {
                            $q->whereNull('max_hours')
                                ->orWhere('max_hours', '>=', $durationHours);
                        });
                })
                ->orderBy('min_hours')
                ->first();

            if ($slabDefinition) {
                $breakdown['slab_definition'] = [
                    'id' => $slabDefinition->id,
                    'name' => $slabDefinition->name,
                    'min_hours' => $slabDefinition->min_hours,
                    'max_hours' => $slabDefinition->max_hours,
                    'type' => $slabDefinition->type
                ];

                // Get the vehicle group pricing
                $vehicleGroupPricing = VehicleGroupPricing::where('vehicle_group_id', $vehicleGroupId)
                    ->where('slab_definition_id', $slabDefinition->id)
                    ->where('is_active', true)
                    ->first();

                if ($vehicleGroupPricing) {
                    $breakdown['pricing_details'] = [
                        'base_rate' => (float) $vehicleGroupPricing->rate,
                        'rate_type' => $vehicleGroupPricing->rate_type,
                        'minimum_charge' => $vehicleGroupPricing->minimum_charge ? (float) $vehicleGroupPricing->minimum_charge : null,
                        'includes_fuel' => $vehicleGroupPricing->includes_fuel,
                        'includes_driver' => $vehicleGroupPricing->includes_driver
                    ];

                    $breakdown['calculated_rate'] = $vehicleGroupPricing->rate;
                    // $breakdown['calculated_rate'] = $this->calculateSlabRateWithType(
                    //     (float) $vehicleGroupPricing->rate,
                    //     $vehicleGroupPricing->rate_type,
                    //     $durationHours,
                    //     $vehicleGroupPricing->minimum_charge ? (float) $vehicleGroupPricing->minimum_charge : null
                    // );
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
            'rate' => 0,
            'source' => null
        ];

        try {
            $vehicleGroupId = $breakdown['vehicle_group_id'];

            if (!$vehicleGroupId) {
                return $breakdown;
            }

            // Check vehicle group specific pricing first
            $commonRatePricing = VehicleGroupCommonRatePricing::whereHas('commonRateDefinition', function ($query) use ($rateKey) {
                $query->where('name', $rateKey)
                    ->where('service_type_id', $this->service_type_id)
                    ->where('is_active', true);
            })
                ->where('vehicle_group_id', $vehicleGroupId)
                ->where('is_active', true)
                ->first();

            if ($commonRatePricing) {
                $breakdown['rate'] = (float) $commonRatePricing->rate;
                $breakdown['source'] = 'vehicle_group_specific';
                return $breakdown;
            }

            // Fallback to default common rate definition
            $commonRateDefinition = VehiclePricingCommonRateDefinition::where('name', $rateKey)
                ->where('service_type_id', $this->service_type_id)
                ->where('is_active', true)
                ->first();

            if ($commonRateDefinition) {
                $breakdown['rate'] = (float) $commonRateDefinition->rate;
                $breakdown['source'] = 'default_definition';
            }
        } catch (\Exception $e) {
            $breakdown['error'] = $e->getMessage();
        }

        return $breakdown;
    }
}
