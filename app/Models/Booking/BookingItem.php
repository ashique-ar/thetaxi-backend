<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Booking\Booking;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\Vehicle;
use App\Models\Driver\Driver;
use App\Models\Service\ServiceType;
use App\Models\User;

class BookingItem extends BaseModel
{
    protected $useUserTracking = false;

    protected $fillable = [
        'booking_id',
        'vehicle_group_id',
        'service_type_id',
        'vehicle_id',
        'driver_id',
        'quantity',
        'unit_price',
        'total_price',
        'pricing_breakdown',
        'addons',
        'customizations',
        'discounts',
        'from_date',
        'from_time',
        'to_date',
        'to_time',
        'pickup_location',
        'dropoff_location',
        'pickup_latitude',
        'pickup_longitude',
        'pickup_landmark',
        'dropoff_latitude',
        'dropoff_longitude',
        'dropoff_landmark',
        'is_self_driven',
        'duration_days',
        'duration_hours',
        'duration_minutes',
        'currency',
        'exchange_rate',
        'status',
        'returned_at',
        'final_priced_at',
        'completed_at',
        'lifecycle_data',
        'requires_approval',
        'approved_at',
        'approved_by',
        'item_type',
        'notes',
        'metadata'
    ];

    protected $casts = [
        'pricing_breakdown' => 'array',
        'addons' => 'array',
        'customizations' => 'array',
        'discounts' => 'array',
        'metadata' => 'array',
        'pickup_location' => 'array',
        'dropoff_location' => 'array',
        // Don't cast dates to prevent timezone conversion
        // 'from_date' => 'datetime:Y-m-d H:i:s',
        // 'to_date' => 'datetime:Y-m-d H:i:s',
        'approved_at' => 'datetime',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'pickup_latitude' => 'decimal:7',
        'pickup_longitude' => 'decimal:7',
        'dropoff_latitude' => 'decimal:7',
        'dropoff_longitude' => 'decimal:7',
        'quantity' => 'integer',
        'duration_days' => 'integer',
        'duration_hours' => 'integer',
        'duration_minutes' => 'integer',
        'requires_approval' => 'boolean',
        'is_self_driven' => 'boolean',
        'returned_at' => 'datetime',
        'final_priced_at' => 'datetime',
        'completed_at' => 'datetime',
        'lifecycle_data' => 'array',
    ];

    /**
     * Custom accessor for from_date to return as Carbon without timezone conversion
     */
    public function getFromDateAttribute($value)
    {
        if (!$value) return null;
        // Parse without timezone conversion
        return \Carbon\Carbon::parse($value, config('app.timezone'));
    }

    /**
     * Custom accessor for to_date to return as Carbon without timezone conversion
     */
    public function getToDateAttribute($value)
    {
        if (!$value) return null;
        // Parse without timezone conversion
        return \Carbon\Carbon::parse($value, config('app.timezone'));
    }

    /**
     * Custom mutator for from_date to store without timezone conversion
     */
    public function setFromDateAttribute($value)
    {
        if (!$value) {
            $this->attributes['from_date'] = null;
            return;
        }
        
        // Always store as-is without any timezone conversion
        if (is_string($value)) {
            // If it's already a datetime string, use it directly
            $this->attributes['from_date'] = $value;
        } else if ($value instanceof \DateTimeInterface) {
            // If it's a DateTime object, format it without timezone conversion
            $this->attributes['from_date'] = $value->format('Y-m-d H:i:s');
        } else {
            // Try to parse it
            try {
                $date = \Carbon\Carbon::parse($value);
                $this->attributes['from_date'] = $date->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $this->attributes['from_date'] = $value;
            }
        }
    }

    /**
     * Custom mutator for to_date to store without timezone conversion
     */
    public function setToDateAttribute($value)
    {
        if (!$value) {
            $this->attributes['to_date'] = null;
            return;
        }
        
        // Always store as-is without any timezone conversion
        if (is_string($value)) {
            // If it's already a datetime string, use it directly
            $this->attributes['to_date'] = $value;
        } else if ($value instanceof \DateTimeInterface) {
            // If it's a DateTime object, format it without timezone conversion
            $this->attributes['to_date'] = $value->format('Y-m-d H:i:s');
        } else {
            // Try to parse it
            try {
                $date = \Carbon\Carbon::parse($value);
                $this->attributes['to_date'] = $date->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $this->attributes['to_date'] = $value;
            }
        }
    }

    /**
     * Get the booking that owns this item
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customerMobileActivities(): HasMany
    {
        return $this->hasMany(BookingCustomerMobileActivity::class, 'booking_item_id');
    }

    /**
     * The item-owned vehicle dispatch/return record.
     */
    public function dispatch(): HasOne
    {
        return $this->hasOne(BookingDispatch::class, 'booking_item_id');
    }

    /**
     * Get the vehicle group for this item
     */
    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class);
    }
    /**
     * Get the vehicle group for this item
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * Get the specific vehicle if assigned
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get the assigned driver
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Get the user who approved this item
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the amount for this item (alias for total_price)
     */
    public function getAmountAttribute()
    {
        return $this->total_price;
    }

    /**
     * Scope for pending items
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for confirmed items
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    /**
     * Scope for items requiring approval
     */
    public function scopeRequiresApproval($query)
    {
        return $query->where('requires_approval', true);
    }

    /**
     * Check if item is approved
     */
    public function isApproved(): bool
    {
        return !is_null($this->approved_at);
    }

    /**
     * Calculate total item value including addons and customizations
     */
    public function calculateTotalValue(): float
    {
        $baseTotal = $this->total_price;
        $addonsTotal = 0;

        if ($this->addons) {
            foreach ($this->addons as $addon) {
                $addonsTotal += ($addon['total_price'] ?? 0);
            }
        }

        return $baseTotal + $addonsTotal;
    }

    /**
     * Get formatted pricing breakdown
     */
    public function getFormattedPricingBreakdown(): array
    {
        return [
            'base_price' => [
                'unit_price' => $this->unit_price,
                'quantity' => $this->quantity,
                'total' => $this->total_price
            ],
            'addons' => $this->addons ?? [],
            'customizations' => $this->customizations ?? [],
            'discounts' => $this->discounts ?? [],
            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'final_total' => $this->calculateTotalValue()
        ];
    }
}
