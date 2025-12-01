<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPriceAdjustmentHistory extends BaseModel
{
    protected $table = 'booking_price_adjustment_history';
    
    protected $fillable = [
        'booking_id',
        'price_adjustment_id',
        'km_range_pricing_rule_id',
        'adjustment_amount',
        'original_amount',
        'final_amount',
        'adjustment_breakdown',
        'adjustment_reason',
        'applied_by_user_id',
        'applied_at',
    ];

    protected $casts = [
        'adjustment_amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'adjustment_breakdown' => 'array',
        'applied_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function priceAdjustment(): BelongsTo
    {
        return $this->belongsTo(PriceAdjustment::class);
    }

    public function kmRangePricingRule(): BelongsTo
    {
        return $this->belongsTo(KmRangePricingRule::class);
    }

    public function appliedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }

    /**
     * Create history record for price adjustment application
     */
    public static function recordAdjustment(
        string $bookingId,
        ?string $priceAdjustmentId = null,
        ?string $kmRangePricingRuleId = null,
        float $adjustmentAmount = 0,
        float $originalAmount = 0,
        float $finalAmount = 0,
        ?array $adjustmentBreakdown = null,
        ?string $adjustmentReason = null
    ): self {
        return static::create([
            'booking_id' => $bookingId,
            'price_adjustment_id' => $priceAdjustmentId,
            'km_range_pricing_rule_id' => $kmRangePricingRuleId,
            'adjustment_amount' => $adjustmentAmount,
            'original_amount' => $originalAmount,
            'final_amount' => $finalAmount,
            'adjustment_breakdown' => $adjustmentBreakdown,
            'adjustment_reason' => $adjustmentReason,
            'applied_by_user_id' => auth()->id(),
            'applied_at' => now(),
        ]);
    }

    /**
     * Get adjustment history for a booking
     */
    public static function getBookingAdjustmentHistory(string $bookingId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('booking_id', $bookingId)
            ->with(['priceAdjustment', 'kmRangePricingRule', 'appliedByUser'])
            ->orderBy('applied_at', 'desc')
            ->get();
    }
}