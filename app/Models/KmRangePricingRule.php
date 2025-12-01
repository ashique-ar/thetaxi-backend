<?php

namespace App\Models;

use App\Enums\BookingLifecycleStatus;
use App\Enums\PricingCalculationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class KmRangePricingRule extends BaseModel
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'name',
        'description',
        'scope_type',
        'service_type_id',
        'vehicle_group_id',
        'min_distance_km',
        'max_distance_km',
        'base_rate',
        'rate_per_km',
        'minimum_charge',
        'maximum_charge',
        'priority',
        'valid_from',
        'valid_to',
        'conditions',
        'is_active'
    ];

    protected $casts = [
        'min_distance_km' => 'decimal:2',
        'max_distance_km' => 'decimal:2',
        'base_rate' => 'decimal:2',
        'rate_per_km' => 'decimal:2',
        'minimum_charge' => 'decimal:2',
        'maximum_charge' => 'decimal:2',
        'priority' => 'integer',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'conditions' => 'array',
        'is_active' => 'boolean'
    ];

    protected $attributes = [
        'is_active' => true,
        'priority' => 10
    ];

    // Relationships
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class);
    }

    // Scoped queries
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeValidForDate($query, $date = null)
    {
        $date = $date ?: now();
        
        return $query->where(function ($q) use ($date) {
            $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
        })->where(function ($q) use ($date) {
            $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date);
        });
    }

    public function scopeForDistance($query, $distance)
    {
        return $query->where('min_distance_km', '<=', $distance)
                    ->where('max_distance_km', '>=', $distance);
    }

    public function scopeGlobal($query)
    {
        return $query->where('scope_type', 'global');
    }

    public function scopeForService($query, $serviceTypeId)
    {
        return $query->where('scope_type', 'service')
                    ->where('service_type_id', $serviceTypeId);
    }

    public function scopeForVehicleGroup($query, $vehicleGroupId)
    {
        return $query->where('scope_type', 'vehicle_group')
                    ->where('vehicle_group_id', $vehicleGroupId);
    }

    public function scopeOrderedByPriority($query)
    {
        return $query->orderBy('priority', 'desc')
                    ->orderBy('created_at', 'desc');
    }

    // Business Logic Methods
    public function isValidForDate($date = null): bool
    {
        $date = $date ?: now();
        
        $validFrom = $this->valid_from ? $this->valid_from->startOfDay() : null;
        $validTo = $this->valid_to ? $this->valid_to->endOfDay() : null;
        
        if ($validFrom && $date < $validFrom) {
            return false;
        }
        
        if ($validTo && $date > $validTo) {
            return false;
        }
        
        return true;
    }

    public function isApplicableForDistance($distance): bool
    {
        return $distance >= $this->min_distance_km && 
               $distance <= $this->max_distance_km;
    }

    public function isApplicableForScope($serviceTypeId = null, $vehicleGroupId = null): bool
    {
        switch ($this->scope_type) {
            case 'global':
                return true;
            case 'service':
                return $this->service_type_id == $serviceTypeId;
            case 'vehicle_group':
                return $this->vehicle_group_id == $vehicleGroupId;
            default:
                return false;
        }
    }

    public function calculatePricing($distance): array
    {
        if (!$this->isApplicableForDistance($distance)) {
            return [
                'applicable' => false,
                'reason' => 'Distance not in range'
            ];
        }

        $baseCharge = $this->base_rate;
        $distanceCharge = $distance * $this->rate_per_km;
        $totalCharge = $baseCharge + $distanceCharge;

        // Apply minimum charge
        if ($this->minimum_charge && $totalCharge < $this->minimum_charge) {
            $totalCharge = $this->minimum_charge;
        }

        // Apply maximum charge
        if ($this->maximum_charge && $totalCharge > $this->maximum_charge) {
            $totalCharge = $this->maximum_charge;
        }

        return [
            'applicable' => true,
            'rule_id' => $this->id,
            'rule_name' => $this->name,
            'base_charge' => $baseCharge,
            'distance_charge' => $distanceCharge,
            'total_charge' => $totalCharge,
            'distance_km' => $distance,
            'rate_per_km' => $this->rate_per_km,
            'minimum_applied' => $this->minimum_charge && $totalCharge == $this->minimum_charge,
            'maximum_applied' => $this->maximum_charge && $totalCharge == $this->maximum_charge
        ];
    }

    public static function calculateBestPricing($distance, $serviceTypeId = null, $vehicleGroupId = null, $date = null): ?array
    {
        $rules = self::active()
                    ->validForDate($date)
                    ->forDistance($distance)
                    ->where(function ($query) use ($serviceTypeId, $vehicleGroupId) {
                        $query->where('scope_type', 'global')
                              ->orWhere(function ($q) use ($serviceTypeId) {
                                  if ($serviceTypeId) {
                                      $q->where('scope_type', 'service')
                                        ->where('service_type_id', $serviceTypeId);
                                  }
                              })
                              ->orWhere(function ($q) use ($vehicleGroupId) {
                                  if ($vehicleGroupId) {
                                      $q->where('scope_type', 'vehicle_group')
                                        ->where('vehicle_group_id', $vehicleGroupId);
                                  }
                              });
                    })
                    ->orderedByPriority()
                    ->get();

        $bestRule = null;
        $bestPricing = null;

        foreach ($rules as $rule) {
            if ($rule->isApplicableForScope($serviceTypeId, $vehicleGroupId)) {
                $pricing = $rule->calculatePricing($distance);
                if ($pricing['applicable']) {
                    $bestRule = $rule;
                    $bestPricing = $pricing;
                    break; // Take the first (highest priority) applicable rule
                }
            }
        }

        return $bestPricing;
    }

    public static function getApplicableRules($distance, $serviceTypeId = null, $vehicleGroupId = null, $date = null): \Illuminate\Database\Eloquent\Collection
    {
        return self::active()
                  ->validForDate($date)
                  ->forDistance($distance)
                  ->where(function ($query) use ($serviceTypeId, $vehicleGroupId) {
                      $query->where('scope_type', 'global')
                            ->orWhere(function ($q) use ($serviceTypeId) {
                                if ($serviceTypeId) {
                                    $q->where('scope_type', 'service')
                                      ->where('service_type_id', $serviceTypeId);
                                }
                            })
                            ->orWhere(function ($q) use ($vehicleGroupId) {
                                if ($vehicleGroupId) {
                                    $q->where('scope_type', 'vehicle_group')
                                      ->where('vehicle_group_id', $vehicleGroupId);
                                }
                            });
                  })
                  ->orderedByPriority()
                  ->get();
    }

    // Activity Log
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'description', 'scope_type', 'service_type_id', 
                'vehicle_group_id', 'min_distance_km', 'max_distance_km',
                'base_rate', 'rate_per_km', 'minimum_charge', 'maximum_charge',
                'priority', 'valid_from', 'valid_to', 'is_active'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Validation Rules
    public static function validationRules($id = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'scope_type' => 'required|in:global,service,vehicle_group',
            'service_type_id' => 'nullable|exists:service_types,id|required_if:scope_type,service',
            'vehicle_group_id' => 'nullable|exists:vehicle_groups,id|required_if:scope_type,vehicle_group',
            'min_distance_km' => 'required|numeric|min:0',
            'max_distance_km' => 'required|numeric|min:0|gt:min_distance_km',
            'base_rate' => 'required|numeric|min:0',
            'rate_per_km' => 'required|numeric|min:0',
            'minimum_charge' => 'nullable|numeric|min:0',
            'maximum_charge' => 'nullable|numeric|min:0|gt:minimum_charge',
            'priority' => 'required|integer|min:1|max:100',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after:valid_from',
            'conditions' => 'nullable|array',
            'is_active' => 'boolean'
        ];
    }
}