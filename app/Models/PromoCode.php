<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * PromoCode model for promotional discount codes.
 * 
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $discount_type
 * @property float $discount_value
 * @property float $minimum_order_amount
 * @property float|null $maximum_discount_amount
 * @property int|null $usage_limit
 * @property int $usage_limit_per_customer
 * @property int $usage_count
 * @property \Carbon\Carbon|null $start_date
 * @property \Carbon\Carbon|null $end_date
 * @property bool $is_active
 * @property string|null $created_user_id
 * @property string|null $updated_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * 
 * @property-read User|null $createdBy
 * @property-read User|null $updatedBy
 * @property-read \Illuminate\Database\Eloquent\Collection|PromoCodeUsage[] $usages
 */
class PromoCode extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'promo_codes';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'minimum_order_amount',
        'maximum_discount_amount',
        'usage_limit',
        'usage_limit_per_customer',
        'usage_count',
        'start_date',
        'end_date',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'discount_value' => 'decimal:2',
        'minimum_order_amount' => 'decimal:2',
        'maximum_discount_amount' => 'decimal:2',
        'usage_limit' => 'integer',
        'usage_limit_per_customer' => 'integer',
        'usage_count' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Discount type constants.
     */
    public const DISCOUNT_TYPE_PERCENTAGE = 'percentage';
    public const DISCOUNT_TYPE_FIXED = 'fixed';

    /**
     * Get the user who created this promo code.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this promo code.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Get all usage records for this promo code.
     */
    public function usages(): HasMany
    {
        return $this->hasMany(PromoCodeUsage::class, 'promo_code_id');
    }

    /**
     * Scope to get only active promo codes.
     * Active codes are those that are enabled and within their date range.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', today());
            })
            ->where(function (Builder $q) {
                $q->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', today());
            });
    }

    /**
     * Scope to find a promo code by its code (case-insensitive).
     */
    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->whereRaw('UPPER(code) = ?', [strtoupper($code)]);
    }

    /**
     * Scope to get codes that haven't reached their usage limit.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('usage_limit')
                ->orWhereColumn('usage_count', '<', 'usage_limit');
        });
    }

    /**
     * Check if the promo code is currently within its valid date range.
     */
    public function isWithinDateRange(): bool
    {
        $today = today();
        
        if ($this->start_date && $today->lt($this->start_date->copy()->startOfDay())) {
            return false;
        }
        
        if ($this->end_date && $today->gt($this->end_date->copy()->startOfDay())) {
            return false;
        }
        
        return true;
    }

    /**
     * Check if the promo code has reached its total usage limit.
     */
    public function hasReachedUsageLimit(): bool
    {
        if ($this->usage_limit === null) {
            return false;
        }
        
        return $this->usage_count >= $this->usage_limit;
    }

    /**
     * Check if a customer has reached their usage limit for this code.
     */
    public function hasCustomerReachedLimit(?string $customerId): bool
    {
        if ($customerId === null || $this->usage_limit_per_customer === null) {
            return false;
        }

        $customerUsageCount = $this->usages()
            ->where('customer_id', $customerId)
            ->count();
        
        return $customerUsageCount >= $this->usage_limit_per_customer;
    }

    /**
     * Check if the order amount meets the minimum requirement.
     */
    public function meetsMinimumOrderAmount(float $orderAmount): bool
    {
        return $orderAmount >= $this->minimum_order_amount;
    }

    /**
     * Calculate the discount amount for a given order amount.
     */
    public function calculateDiscount(float $orderAmount): float
    {
        if ($this->discount_type === self::DISCOUNT_TYPE_PERCENTAGE) {
            $discount = $orderAmount * ($this->discount_value / 100);
            
            // Apply maximum discount cap if set
            if ($this->maximum_discount_amount !== null) {
                $discount = min($discount, $this->maximum_discount_amount);
            }
        } else {
            // Fixed discount - cannot exceed order amount
            $discount = min($this->discount_value, $orderAmount);
        }
        
        return round($discount, 2);
    }

    /**
     * Validate if the promo code can be applied.
     * Returns an array with 'valid' boolean and 'error' message if invalid.
     */
    public function validate(float $orderAmount, ?string $customerId = null): array
    {
        if (!$this->is_active) {
            return [
                'valid' => false,
                'error_code' => 'PROMO_CODE_INACTIVE',
                'message' => 'This promo code is not active.',
            ];
        }

        if (!$this->isWithinDateRange()) {
            $now = now();
            if ($this->start_date && $now->lt($this->start_date)) {
                return [
                    'valid' => false,
                    'error_code' => 'PROMO_CODE_NOT_YET_ACTIVE',
                    'message' => 'This promo code is not yet active.',
                ];
            }
            return [
                'valid' => false,
                'error_code' => 'PROMO_CODE_EXPIRED',
                'message' => 'This promo code has expired.',
            ];
        }

        if ($this->hasReachedUsageLimit()) {
            return [
                'valid' => false,
                'error_code' => 'PROMO_CODE_USAGE_LIMIT_REACHED',
                'message' => 'This promo code has reached its usage limit.',
            ];
        }

        if ($this->hasCustomerReachedLimit($customerId)) {
            return [
                'valid' => false,
                'error_code' => 'PROMO_CODE_CUSTOMER_LIMIT_REACHED',
                'message' => 'You have already used this promo code the maximum number of times.',
            ];
        }

        if (!$this->meetsMinimumOrderAmount($orderAmount)) {
            return [
                'valid' => false,
                'error_code' => 'PROMO_CODE_MINIMUM_NOT_MET',
                'message' => sprintf(
                    'Minimum order amount of %s is required to use this promo code.',
                    number_format(floor(max(0, $this->minimum_order_amount)), 0)
                ),
                'details' => [
                    'minimum_required' => $this->minimum_order_amount,
                    'current_total' => $orderAmount,
                ],
            ];
        }

        return [
            'valid' => true,
            'discount' => $this->calculateDiscount($orderAmount),
        ];
    }

    /**
     * Increment the usage count.
     */
    public function incrementUsageCount(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Get available discount type options.
     */
    public static function getDiscountTypeOptions(): array
    {
        return [
            self::DISCOUNT_TYPE_PERCENTAGE => 'Percentage',
            self::DISCOUNT_TYPE_FIXED => 'Fixed Amount',
        ];
    }
}
