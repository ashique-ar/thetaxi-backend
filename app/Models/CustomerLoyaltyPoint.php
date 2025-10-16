<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CustomerLoyaltyPoint extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'customer_id',
        'total_points',
        'available_points',
        'pending_points',
        'redeemed_points',
        'expired_points',
        'current_tier',
        'tier_progress_points',
        'next_tier',
        'points_to_next_tier',
        'earning_rate_multiplier',
        'redemption_rate_multiplier',
        'total_bookings',
        'total_spent',
        'last_activity_date',
        'tier_upgrade_date',
        'tier_downgrade_date',
        'is_vip',
        'special_privileges',
        'notes',
    ];

    protected $casts = [
        'id' => 'string',
        'total_spent' => 'decimal:2',
        'earning_rate_multiplier' => 'decimal:2',
        'redemption_rate_multiplier' => 'decimal:2',
        'last_activity_date' => 'date',
        'tier_upgrade_date' => 'date',
        'tier_downgrade_date' => 'date',
        'is_vip' => 'boolean',
        'special_privileges' => 'array',
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
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyPointTransaction::class, 'customer_id', 'customer_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'current_tier', 'name');
    }

    public function nextTierModel(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'next_tier', 'name');
    }

    // Scopes
    public function scopeVip($query)
    {
        return $query->where('is_vip', true);
    }

    public function scopeByTier($query, string $tier)
    {
        return $query->where('current_tier', $tier);
    }

    public function scopeActiveInLastDays($query, int $days = 30)
    {
        return $query->where('last_activity_date', '>=', Carbon::now()->subDays($days));
    }

    // Methods
    public function earnPoints(int $points, string $reason, ?string $bookingId = null, array $metadata = []): LoyaltyPointTransaction
    {
        $transaction = LoyaltyPointTransaction::create([
            'customer_id' => $this->customer_id,
            'booking_id' => $bookingId,
            'type' => 'earned',
            'points' => $points,
            'balance_before' => $this->available_points,
            'balance_after' => $this->available_points + $points,
            'earning_reason' => $reason,
            'description' => "Earned {$points} points: {$reason}",
            'metadata' => $metadata,
            'processed_at' => now(),
            'expires_at' => $this->calculatePointsExpiryDate(),
            'reference_number' => $this->generateReferenceNumber('EARN'),
        ]);

        $this->update([
            'total_points' => $this->total_points + $points,
            'available_points' => $this->available_points + $points,
            'last_activity_date' => now()->toDateString(),
        ]);

        $this->checkTierUpgrade();

        return $transaction;
    }

    public function redeemPoints(int $points, float $redemptionValue, string $reason, ?string $bookingId = null): LoyaltyPointTransaction
    {
        if ($points > $this->available_points) {
            throw new \Exception('Insufficient points for redemption');
        }

        $redemptionRate = $redemptionValue / $points;

        $transaction = LoyaltyPointTransaction::create([
            'customer_id' => $this->customer_id,
            'booking_id' => $bookingId,
            'type' => 'redeemed',
            'points' => -$points,
            'balance_before' => $this->available_points,
            'balance_after' => $this->available_points - $points,
            'redemption_value' => $redemptionValue,
            'redemption_rate' => $redemptionRate,
            'redemption_reason' => $reason,
            'description' => "Redeemed {$points} points for LKR {$redemptionValue}: {$reason}",
            'processed_at' => now(),
            'reference_number' => $this->generateReferenceNumber('REDEEM'),
        ]);

        $this->update([
            'available_points' => $this->available_points - $points,
            'redeemed_points' => $this->redeemed_points + $points,
            'last_activity_date' => now()->toDateString(),
        ]);

        return $transaction;
    }

    public function adjustPoints(int $points, string $reason, ?string $processedBy = null): LoyaltyPointTransaction
    {
        $transaction = LoyaltyPointTransaction::create([
            'customer_id' => $this->customer_id,
            'type' => 'adjusted',
            'points' => $points,
            'balance_before' => $this->available_points,
            'balance_after' => $this->available_points + $points,
            'description' => "Points adjustment: {$reason}",
            'internal_notes' => $reason,
            'processed_by' => $processedBy,
            'processed_at' => now(),
            'reference_number' => $this->generateReferenceNumber('ADJ'),
        ]);

        $this->update([
            'total_points' => max(0, $this->total_points + $points),
            'available_points' => max(0, $this->available_points + $points),
        ]);

        if ($points > 0) {
            $this->checkTierUpgrade();
        }

        return $transaction;
    }

    public function expirePoints(int $points, string $reason = 'Points expired'): LoyaltyPointTransaction
    {
        $pointsToExpire = min($points, $this->available_points);

        $transaction = LoyaltyPointTransaction::create([
            'customer_id' => $this->customer_id,
            'type' => 'expired',
            'points' => -$pointsToExpire,
            'balance_before' => $this->available_points,
            'balance_after' => $this->available_points - $pointsToExpire,
            'description' => "Expired {$pointsToExpire} points: {$reason}",
            'expired_at' => now()->toDateString(),
            'processed_at' => now(),
            'reference_number' => $this->generateReferenceNumber('EXP'),
        ]);

        $this->update([
            'available_points' => $this->available_points - $pointsToExpire,
            'expired_points' => $this->expired_points + $pointsToExpire,
        ]);

        return $transaction;
    }

    public function checkTierUpgrade(): void
    {
        $newTier = LoyaltyTier::where('min_points', '<=', $this->total_points)
            ->where(function ($query) {
                $query->whereNull('max_points')
                      ->orWhere('max_points', '>=', $this->total_points);
            })
            ->where('is_active', true)
            ->orderBy('min_points', 'desc')
            ->first();

        if ($newTier && $newTier->name !== $this->current_tier) {
            $oldTier = $this->current_tier;
            
            $this->update([
                'current_tier' => $newTier->name,
                'tier_upgrade_date' => now()->toDateString(),
                'earning_rate_multiplier' => $newTier->points_earning_multiplier,
                'redemption_rate_multiplier' => $newTier->points_redemption_multiplier,
            ]);

            // Award bonus points for tier upgrade
            if ($newTier->bonus_points_on_upgrade > 0) {
                $this->earnPoints(
                    $newTier->bonus_points_on_upgrade,
                    "Tier upgrade bonus from {$oldTier} to {$newTier->name}",
                    null,
                    ['tier_upgrade' => true, 'old_tier' => $oldTier, 'new_tier' => $newTier->name]
                );
            }
        }

        $this->updateNextTierInfo();
    }

    private function updateNextTierInfo(): void
    {
        $nextTier = LoyaltyTier::where('min_points', '>', $this->total_points)
            ->where('is_active', true)
            ->orderBy('min_points', 'asc')
            ->first();

        $this->update([
            'next_tier' => $nextTier?->name,
            'points_to_next_tier' => $nextTier ? max(0, $nextTier->min_points - $this->total_points) : 0,
        ]);
    }

    private function calculatePointsExpiryDate(): Carbon
    {
        // Points expire after 2 years by default
        return Carbon::now()->addYears(2);
    }

    private function generateReferenceNumber(string $type): string
    {
        return $type . '-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }

    public function getPointsValueAttribute(): float
    {
        // Default redemption rate: 100 points = 1 LKR
        $defaultRate = 0.01;
        return $this->available_points * ($this->redemption_rate_multiplier * $defaultRate);
    }

    public function canRedeemPoints(int $points): bool
    {
        return $points <= $this->available_points && $points > 0;
    }

    public function getTierProgressPercentageAttribute(): float
    {
        if (!$this->next_tier || $this->points_to_next_tier <= 0) {
            return 100;
        }

        $currentTier = LoyaltyTier::where('name', $this->current_tier)->first();
        $nextTier = LoyaltyTier::where('name', $this->next_tier)->first();

        if (!$currentTier || !$nextTier) {
            return 0;
        }

        $tierRange = $nextTier->min_points - $currentTier->min_points;
        $currentProgress = $this->total_points - $currentTier->min_points;

        return $tierRange > 0 ? min(100, ($currentProgress / $tierRange) * 100) : 100;
    }
}