<?php

namespace App\Models;

use App\Models\Booking\Booking;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * PromoCodeUsage model for tracking promo code redemptions.
 * 
 * @property string $id
 * @property string $promo_code_id
 * @property string|null $customer_id
 * @property string|null $booking_id
 * @property float $discount_amount
 * @property float $order_amount
 * @property \Carbon\Carbon $used_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * 
 * @property-read PromoCode $promoCode
 * @property-read Customer|null $customer
 * @property-read Booking|null $booking
 */
class PromoCodeUsage extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'promo_code_usages';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'promo_code_id',
        'customer_id',
        'booking_id',
        'discount_amount',
        'order_amount',
        'used_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'discount_amount' => 'decimal:2',
        'order_amount' => 'decimal:2',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the promo code that was used.
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_id');
    }

    /**
     * Get the customer who used the promo code.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Get the booking associated with this usage.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /**
     * Scope to filter by promo code.
     */
    public function scopeForPromoCode(Builder $query, string $promoCodeId): Builder
    {
        return $query->where('promo_code_id', $promoCodeId);
    }

    /**
     * Scope to filter by customer.
     */
    public function scopeForCustomer(Builder $query, string $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeUsedBetween(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('used_at', [$startDate, $endDate]);
    }

    /**
     * Create a new usage record for a promo code redemption.
     */
    public static function recordUsage(
        string $promoCodeId,
        ?string $customerId,
        ?string $bookingId,
        float $discountAmount,
        float $orderAmount
    ): self {
        return self::create([
            'promo_code_id' => $promoCodeId,
            'customer_id' => $customerId,
            'booking_id' => $bookingId,
            'discount_amount' => $discountAmount,
            'order_amount' => $orderAmount,
            'used_at' => now(),
        ]);
    }
}
