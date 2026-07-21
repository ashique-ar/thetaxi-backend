<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BookingPriceAdjustmentHistory extends BaseModel
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'booking_id',
        'price_adjustment_id',
        'adjustment_type',
        'value_type',
        'value',
        'applied_amount',
        'created_user_id'
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'applied_amount' => 'decimal:2'
    ];

    // Relationships
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function priceAdjustment(): BelongsTo
    {
        return $this->belongsTo(PriceAdjustment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    // Scoped queries
    public function scopeForBooking($query, $bookingId)
    {
        return $query->where('booking_id', $bookingId);
    }

    public function scopeForAdjustment($query, $adjustmentId)
    {
        return $query->where('price_adjustment_id', $adjustmentId);
    }

    public function scopeDiscounts($query)
    {
        return $query->where('adjustment_type', 'discount');
    }

    public function scopeMarkups($query)
    {
        return $query->where('adjustment_type', 'markup');
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    // Business Logic Methods
    public function isDiscount(): bool
    {
        return $this->adjustment_type === 'discount';
    }

    public function isMarkup(): bool
    {
        return $this->adjustment_type === 'markup';
    }

    public function isPercentageBased(): bool
    {
        return $this->value_type === 'percentage';
    }

    public function isFixedAmount(): bool
    {
        return $this->value_type === 'fixed';
    }

    // Static Methods for Analytics
    public static function getBookingAdjustmentSummary($bookingId): array
    {
        $history = self::forBooking($bookingId)->get();
        
        $totalDiscounts = $history->where('adjustment_type', 'discount')->sum('applied_amount');
        $totalMarkups = $history->where('adjustment_type', 'markup')->sum('applied_amount');
        $netAdjustment = $totalMarkups - $totalDiscounts;
        
        return [
            'total_adjustments' => $history->count(),
            'total_discounts' => $totalDiscounts,
            'total_markups' => $totalMarkups,
            'net_adjustment' => $netAdjustment,
            'discount_count' => $history->where('adjustment_type', 'discount')->count(),
            'markup_count' => $history->where('adjustment_type', 'markup')->count(),
            'details' => $history->toArray()
        ];
    }

    public static function getAdjustmentUsageStats($adjustmentId, $startDate = null, $endDate = null): array
    {
        $query = self::forAdjustment($adjustmentId);
        
        if ($startDate && $endDate) {
            $query->byDateRange($startDate, $endDate);
        }
        
        $records = $query->get();
        
        return [
            'total_uses' => $records->count(),
            'total_amount_applied' => $records->sum('applied_amount'),
            'unique_bookings' => $records->pluck('booking_id')->unique()->count(),
            'average_amount' => $records->count() > 0 ? $records->avg('applied_amount') : 0,
            'max_amount' => $records->max('applied_amount'),
            'min_amount' => $records->min('applied_amount'),
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ];
    }

    public static function getSystemwideAdjustmentReport($startDate, $endDate): array
    {
        $records = self::byDateRange($startDate, $endDate)->get();
        
        $discounts = $records->where('adjustment_type', 'discount');
        $markups = $records->where('adjustment_type', 'markup');
        
        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'total_adjustments' => $records->count(),
            'unique_bookings' => $records->pluck('booking_id')->unique()->count(),
            'discounts' => [
                'count' => $discounts->count(),
                'total_amount' => $discounts->sum('applied_amount'),
                'average_amount' => $discounts->count() > 0 ? $discounts->avg('applied_amount') : 0
            ],
            'markups' => [
                'count' => $markups->count(),
                'total_amount' => $markups->sum('applied_amount'),
                'average_amount' => $markups->count() > 0 ? $markups->avg('applied_amount') : 0
            ],
            'net_impact' => $markups->sum('applied_amount') - $discounts->sum('applied_amount'),
            'top_adjustments' => $records->groupBy('price_adjustment_id')
                                       ->map(function ($group) {
                                           return [
                                               'adjustment_id' => $group->first()->price_adjustment_id,
                                               'uses' => $group->count(),
                                               'total_amount' => $group->sum('applied_amount')
                                           ];
                                       })
                                       ->sortByDesc('uses')
                                       ->take(10)
                                       ->values()
                                       ->toArray()
        ];
    }

    // Activity Log
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'booking_id', 'price_adjustment_id', 'adjustment_type',
                'value_type', 'value', 'applied_amount'
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    // Validation Rules
    public static function validationRules($id = null): array
    {
        return [
            'booking_id' => 'required|exists:bookings,id',
            'price_adjustment_id' => 'required|exists:price_adjustments,id',
            'adjustment_type' => 'required|in:discount,markup',
            'value_type' => 'required|in:fixed,percentage',
            'value' => 'required|numeric|min:0',
            'applied_amount' => 'required|numeric|min:0'
        ];
    }
}
