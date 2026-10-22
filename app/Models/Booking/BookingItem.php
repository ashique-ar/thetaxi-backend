<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Services\TimezoneService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Booking\Booking;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\Vehicle;
use App\Models\Driver\Driver;
use App\Models\Service\ServiceType;
use App\Models\User;
use Carbon\Carbon;

class BookingItem extends BaseModel
{
    protected $useUserTracking = false;

    protected $fillable = [
        'booking_id',
        'item_code',
        'vehicle_group_id',
        'service_type_id',
        'vehicle_id',
        'driver_id',
        'quantity',
        'unit_price',
        'total_price',
        'price_override_amount',
        'price_override_reason',
        'price_overridden_by',
        'price_overridden_at',
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
        'unit_price' => 'decimal:4',
        'total_price' => 'decimal:4',
        'price_override_amount' => 'decimal:4',
        'price_overridden_at' => 'datetime',
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

    protected static function boot()
    {
        parent::boot();

        static::creating(function (BookingItem $item): void {
            if (empty($item->booking_id)) {
                return;
            }

            $booking = Booking::query()->whereKey($item->booking_id)
                ->first(['id', 'is_corporate_booking', 'corporate_account_id']);
            $corporateId = $booking?->is_corporate_booking ? $booking->corporate_account_id : null;
            $references = app(\App\Services\CorporateReferenceNumberService::class);

            if (empty($item->item_code) && $corporateId) {
                $item->item_code = $references->next((string) $corporateId, 'item');
            }
            if (!empty($item->item_code)) {
                $references->reserveProvided((string) $item->item_code, 'item', $corporateId ? (string) $corporateId : null, 'item_code');
            }
        });
    }

    /**
     * Custom accessor for from_date to return as Carbon with user timezone conversion
     * Returns UTC time converted to user's local timezone
     */
    public function getFromDateAttribute($value)
    {
        if (!$value)
            return null;
        // Parse as UTC and convert to user's timezone for display
        return TimezoneService::fromUtc($value);
    }

    /**
     * Custom accessor for to_date to return as Carbon with user timezone conversion
     * Returns UTC time converted to user's local timezone
     */
    public function getToDateAttribute($value)
    {
        if (!$value)
            return null;
        // Parse as UTC and convert to user's timezone for display
        return TimezoneService::fromUtc($value);
    }

    /**
     * Custom mutator for from_date to convert user timezone to UTC for storage
     * Stores the date in UTC format to the database
     */
    public function setFromDateAttribute($value)
    {
        if (!$value) {
            $this->attributes['from_date'] = null;
            return;
        }

        // Convert from user timezone to UTC for storage
        $utcDate = TimezoneService::toUtc($value);
        $this->attributes['from_date'] = $utcDate->format('Y-m-d H:i:s');
    }

    /**
     * Custom mutator for to_date to convert user timezone to UTC for storage
     * Stores the date in UTC format to the database
     */
    public function setToDateAttribute($value)
    {
        if (!$value) {
            $this->attributes['to_date'] = null;
            return;
        }

        // Convert from user timezone to UTC for storage
        $utcDate = TimezoneService::toUtc($value);
        $this->attributes['to_date'] = $utcDate->format('Y-m-d H:i:s');
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

    public function qc(): HasOne
    {
        return $this->hasOne(BookingQC::class, 'booking_item_id');
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

    /** Calculate the finalized item value after saved add-ons and discounts. */
    public function calculateTotalValue(): float
    {
        $subtotal = (float) $this->total_price + $this->calculateAddonsTotal();
        $discount = data_get($this->pricing_breakdown, 'discount_summary.total_discount_amount');

        if ($discount === null) {
            $discount = collect($this->discounts ?? [])->sum(function ($row) use ($subtotal) {
                if (! is_array($row)) return 0;
                if (isset($row['amount']) || isset($row['discount_amount'])) {
                    return (float) ($row['amount'] ?? $row['discount_amount']);
                }
                $value = (float) ($row['value'] ?? 0);
                return ($row['method'] ?? null) === 'percentage' ? $subtotal * $value / 100 : $value;
            });
        }

        return max(0, $subtotal - (float) $discount);
    }

    /** Calculate the saved pre-completion estimate, including add-ons and discounts. */
    public function calculateEstimatedTotalValue(): float
    {
        $pricing = is_array($this->pricing_breakdown) ? $this->pricing_breakdown : [];
        $addonsTotal = $this->calculateAddonsTotal();
        $baseTotal = (float) (data_get($pricing, 'base_pricing.total_amount') ?? $this->total_price ?? 0);
        $subtotal = data_get($pricing, 'summary.total');

        if ($subtotal === null) {
            $subtotal = $baseTotal + (float) (data_get($pricing, 'addons_pricing.addons_total') ?? $addonsTotal);
        }

        $discount = data_get($pricing, 'discount_summary.total_discount_amount');
        if ($discount === null) {
            $discount = collect($this->discounts ?? [])->sum(function ($row) use ($subtotal) {
                if (! is_array($row)) return 0;
                if (isset($row['amount']) || isset($row['discount_amount'])) {
                    return (float) ($row['amount'] ?? $row['discount_amount']);
                }
                $value = (float) ($row['value'] ?? 0);
                return ($row['method'] ?? null) === 'percentage' ? $subtotal * $value / 100 : $value;
            });
        }

        return max(0, (float) (data_get($pricing, 'final_breakdown.amount_after_discount')
            ?? data_get($pricing, 'final_breakdown.final_amount')
            ?? ($subtotal - (float) $discount)));
    }

    private function calculateAddonsTotal(): float
    {
        $savedTotal = data_get($this->pricing_breakdown, 'addons_pricing.addons_total')
            ?? data_get($this->pricing_breakdown, 'addon_charges');

        if (is_numeric($savedTotal)) {
            return (float) $savedTotal;
        }

        return collect($this->addons ?? [])->sum(fn ($addon) => is_array($addon)
            ? (float) ($addon['total_price'] ?? $addon['calculated_amount'] ?? 0)
            : 0);
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
