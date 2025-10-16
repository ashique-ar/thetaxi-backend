<?php

namespace App\Models\Booking;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BookingDiscount extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'booking_id',
        'discount_name',
        'description',
        'type',
        'value',
        'discount_amount',
        'original_amount',
        'final_amount',
        'loyalty_points_used',
        'points_to_amount_rate',
        'application_method',
        'discount_code',
        'applied_by',
        'applied_at',
        'requires_approval',
        'approval_status',
        'approved_by',
        'approved_at',
        'approval_notes',
        'valid_from',
        'valid_until',
        'is_active',
        'conditions_met',
        'calculation_details',
        'internal_notes',
    ];

    protected $casts = [
        'id' => 'string',
        'value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'points_to_amount_rate' => 'decimal:4',
        'applied_at' => 'datetime',
        'approved_at' => 'datetime',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
        'conditions_met' => 'array',
        'calculation_details' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            $model->applied_at = $model->applied_at ?? now();
        });
    }

    // Relationships
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePending($query)
    {
        return $query->where('approval_status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('approval_status', 'approved');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // Methods
    public function approve(?string $approvedBy = null, ?string $notes = null): void
    {
        $this->update([
            'approval_status' => 'approved',
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'approval_notes' => $notes,
        ]);
    }

    public function reject(?string $approvedBy = null, ?string $notes = null): void
    {
        $this->update([
            'approval_status' => 'rejected',
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'approval_notes' => $notes,
            'is_active' => false,
        ]);
    }

    public function getFormattedDiscountAttribute(): string
    {
        if ($this->type === 'percentage') {
            return $this->value . '%';
        } elseif ($this->type === 'fixed_amount') {
            return 'LKR ' . number_format($this->value, 2);
        } elseif ($this->type === 'loyalty_points') {
            return $this->loyalty_points_used . ' points';
        }
        
        return 'Unknown';
    }

    public function getSavingsAmountAttribute(): float
    {
        return $this->discount_amount;
    }

    public function isExpired(): bool
    {
        return $this->valid_until && now()->gt($this->valid_until);
    }

    public function isPending(): bool
    {
        return $this->approval_status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->approval_status === 'rejected';
    }
}