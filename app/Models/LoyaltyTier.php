<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LoyaltyTier extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'color_code',
        'icon',
        'min_points',
        'max_points',
        'min_bookings',
        'min_total_spent',
        'months_to_maintain',
        'points_earning_multiplier',
        'points_redemption_multiplier',
        'discount_multiplier',
        'bonus_points_on_upgrade',
        'privileges',
        'exclusive_discounts',
        'priority_booking',
        'free_cancellation',
        'priority_support',
        'sort_order',
        'is_active',
        'is_default',
        'auto_upgrade',
        'auto_downgrade',
        'grace_period_months',
        'min_points_to_maintain',
    ];

    protected $casts = [
        'id' => 'string',
        'min_total_spent' => 'decimal:2',
        'points_earning_multiplier' => 'decimal:2',
        'points_redemption_multiplier' => 'decimal:2',
        'discount_multiplier' => 'decimal:2',
        'privileges' => 'array',
        'exclusive_discounts' => 'array',
        'priority_booking' => 'boolean',
        'free_cancellation' => 'boolean',
        'priority_support' => 'boolean',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'auto_upgrade' => 'boolean',
        'auto_downgrade' => 'boolean',
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

    // Relationships
    public function customers(): HasMany
    {
        return $this->hasMany(CustomerLoyaltyPoint::class, 'current_tier', 'name');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('min_points');
    }

    // Methods
    public function isEligibleForUpgrade(CustomerLoyaltyPoint $customerPoints): bool
    {
        return $customerPoints->total_points >= $this->min_points;
    }

    public function getNextTier(): ?LoyaltyTier
    {
        return static::where('min_points', '>', $this->min_points)
            ->where('is_active', true)
            ->orderBy('min_points', 'asc')
            ->first();
    }

    public function getPreviousTier(): ?LoyaltyTier
    {
        return static::where('min_points', '<', $this->min_points)
            ->where('is_active', true)
            ->orderBy('min_points', 'desc')
            ->first();
    }
}