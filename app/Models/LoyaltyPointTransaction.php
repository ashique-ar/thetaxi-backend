<?php

namespace App\Models;

use App\Models\Booking\Booking;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LoyaltyPointTransaction extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'customer_id',
        'booking_id',
        'discount_id',
        'type',
        'points',
        'balance_before',
        'balance_after',
        'amount_spent',
        'earning_rate',
        'earning_reason',
        'redemption_value',
        'redemption_rate',
        'redemption_reason',
        'expires_at',
        'expired_at',
        'reference_number',
        'metadata',
        'description',
        'internal_notes',
        'processed_by',
        'processed_at',
        'status',
    ];

    protected $casts = [
        'id' => 'string',
        'amount_spent' => 'decimal:2',
        'earning_rate' => 'decimal:4',
        'redemption_value' => 'decimal:2',
        'redemption_rate' => 'decimal:4',
        'expires_at' => 'date',
        'expired_at' => 'date',
        'processed_at' => 'datetime',
        'metadata' => 'array',
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

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(BookingDiscount::class, 'discount_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // Scopes
    public function scopeEarned($query)
    {
        return $query->where('type', 'earned');
    }

    public function scopeRedeemed($query)
    {
        return $query->where('type', 'redeemed');
    }

    public function scopeExpired($query)
    {
        return $query->where('type', 'expired');
    }

    public function scopeForCustomer($query, string $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}