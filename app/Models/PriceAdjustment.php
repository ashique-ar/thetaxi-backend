<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class PriceAdjustment extends BaseModel
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'name',
        'description',
        'adjustment_type',
        'value_type',
        'value',
        'scope_type',
        'service_type_id',
        'vehicle_group_id',
        'valid_from',
        'valid_to',
        'usage_limit',
        'usage_count',
        'conditions',
        'is_active'
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'usage_limit' => 'integer',
        'usage_count' => 'integer',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'conditions' => 'array',
        'is_active' => 'boolean'
    ];

    protected $attributes = [
        'is_active' => true,
        'usage_count' => 0
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

    public function bookingHistory(): HasMany
    {
        return $this->hasMany(BookingPriceAdjustmentHistory::class);
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

    public function scopeWithinUsageLimit($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('usage_limit')
              ->orWhereRaw('usage_count < usage_limit');
        });
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

    public function scopeDiscounts($query)
    {
        return $query->where('adjustment_type', 'discount');
    }

    public function scopeMarkups($query)
    {
        return $query->where('adjustment_type', 'markup');
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

    public function isWithinUsageLimit(): bool
    {
        if (!$this->usage_limit) {
            return true; // No limit set
        }
        
        return $this->usage_count < $this->usage_limit;
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

    public function canBeApplied($serviceTypeId = null, $vehicleGroupId = null, $date = null): bool
    {
        return $this->is_active &&
               $this->isValidForDate($date) &&
               $this->isWithinUsageLimit() &&
               $this->isApplicableForScope($serviceTypeId, $vehicleGroupId);
    }

    public function calculateAdjustment($originalAmount): array
    {
        if (!$this->canBeApplied()) {
            return [
                'applicable' => false,
                'reason' => 'Adjustment not applicable'
            ];
        }

        $adjustmentAmount = 0;
        
        if ($this->value_type === 'percentage') {
            $adjustmentAmount = ($originalAmount * $this->value) / 100;
        } else {
            $adjustmentAmount = $this->value;
        }

        $finalAmount = $originalAmount;
        
        if ($this->adjustment_type === 'discount') {
            $finalAmount = max(0, $originalAmount - $adjustmentAmount);
        } else { // markup
            $finalAmount = $originalAmount + $adjustmentAmount;
        }

        return [
            'applicable' => true,
            'adjustment_id' => $this->id,
            'adjustment_name' => $this->name,
            'adjustment_type' => $this->adjustment_type,
            'value_type' => $this->value_type,
            'value' => $this->value,
            'original_amount' => $originalAmount,
            'adjustment_amount' => $adjustmentAmount,
            'final_amount' => $finalAmount,
            'savings' => $this->adjustment_type === 'discount' ? $adjustmentAmount : 0,
            'markup' => $this->adjustment_type === 'markup' ? $adjustmentAmount : 0
        ];
    }

    public function recordUsage($bookingId = null): void
    {
        $this->increment('usage_count');
        
        if ($bookingId) {
            BookingPriceAdjustmentHistory::create([
                'booking_id' => $bookingId,
                'price_adjustment_id' => $this->id,
                'adjustment_type' => $this->adjustment_type,
                'value_type' => $this->value_type,
                'value' => $this->value,
                'applied_amount' => 0, // This should be set by the caller
                'created_user_id' => auth()->id()
            ]);
        }
    }

    public static function getApplicableAdjustments($serviceTypeId = null, $vehicleGroupId = null, $date = null): \Illuminate\Database\Eloquent\Collection
    {
        return self::active()
                  ->validForDate($date)
                  ->withinUsageLimit()
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
                  ->orderBy('adjustment_type', 'desc') // Markups first, then discounts
                  ->orderBy('created_at', 'desc')
                  ->get();
    }

    public static function applyAdjustments($originalAmount, $serviceTypeId = null, $vehicleGroupId = null, $date = null, $bookingId = null): array
    {
        $adjustments = self::getApplicableAdjustments($serviceTypeId, $vehicleGroupId, $date);
        
        $currentAmount = $originalAmount;
        $appliedAdjustments = [];
        $totalSavings = 0;
        $totalMarkup = 0;

        foreach ($adjustments as $adjustment) {
            if ($adjustment->isApplicableForScope($serviceTypeId, $vehicleGroupId)) {
                $result = $adjustment->calculateAdjustment($currentAmount);
                
                if ($result['applicable']) {
                    $appliedAdjustments[] = $result;
                    $currentAmount = $result['final_amount'];
                    
                    if ($adjustment->adjustment_type === 'discount') {
                        $totalSavings += $result['adjustment_amount'];
                    } else {
                        $totalMarkup += $result['adjustment_amount'];
                    }
                    
                    // Record usage if booking ID is provided
                    if ($bookingId) {
                        $adjustment->recordUsage($bookingId);
                        
                        // Update the history record with the actual applied amount
                        $history = BookingPriceAdjustmentHistory::where('booking_id', $bookingId)
                                                               ->where('price_adjustment_id', $adjustment->id)
                                                               ->latest()
                                                               ->first();
                        if ($history) {
                            $history->update(['applied_amount' => $result['adjustment_amount']]);
                        }
                    }
                }
            }
        }

        return [
            'original_amount' => $originalAmount,
            'final_amount' => $currentAmount,
            'total_adjustment' => $currentAmount - $originalAmount,
            'total_savings' => $totalSavings,
            'total_markup' => $totalMarkup,
            'adjustments_applied' => count($appliedAdjustments),
            'adjustment_details' => $appliedAdjustments
        ];
    }

    public function getUsageStatistics(): array
    {
        $totalBookings = $this->bookingHistory()->count();
        $recentBookings = $this->bookingHistory()
                              ->where('created_at', '>=', now()->subMonth())
                              ->count();
        
        $usagePercentage = $this->usage_limit ? 
                          ($this->usage_count / $this->usage_limit * 100) : 
                          null;

        return [
            'total_usage' => $this->usage_count,
            'usage_limit' => $this->usage_limit,
            'usage_percentage' => $usagePercentage,
            'total_bookings' => $totalBookings,
            'recent_bookings' => $recentBookings,
            'remaining_uses' => $this->usage_limit ? 
                               max(0, $this->usage_limit - $this->usage_count) : 
                               null
        ];
    }

    // Activity Log
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'description', 'adjustment_type', 'value_type', 'value',
                'scope_type', 'service_type_id', 'vehicle_group_id',
                'valid_from', 'valid_to', 'usage_limit', 'usage_count', 'is_active'
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
            'adjustment_type' => 'required|in:discount,markup',
            'value_type' => 'required|in:fixed,percentage',
            'value' => 'required|numeric|min:0',
            'scope_type' => 'required|in:global,service,vehicle_group',
            'service_type_id' => 'nullable|exists:service_types,id|required_if:scope_type,service',
            'vehicle_group_id' => 'nullable|exists:vehicle_groups,id|required_if:scope_type,vehicle_group',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after:valid_from',
            'usage_limit' => 'nullable|integer|min:1',
            'conditions' => 'nullable|array',
            'is_active' => 'boolean'
        ];
    }
}