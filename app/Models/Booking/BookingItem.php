<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'currency',
        'exchange_rate',
        'status',
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
        'from_date' => 'datetime',
        'to_date' => 'datetime',
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
        'requires_approval' => 'boolean',
        'is_self_driven' => 'boolean'
    ];

    /**
     * Get the booking that owns this item
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
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
