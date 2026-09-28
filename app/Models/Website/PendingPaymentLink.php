<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use App\Models\Booking\Booking;

class PendingPaymentLink extends BaseModel
{
    protected $table = 'pending_payment_links';

    protected $fillable = [
        'booking_id',
        'token',
        'type',
        'booking_context',
        'revision_item_ids',
        'revision_amount_due',
        'revision_total',
        'revision_history',
        'amount_due',
        'expires_at',
        'accessed_at',
        'access_count',
        'invalidated_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accessed_at' => 'datetime',
        'invalidated_at' => 'datetime',
        'booking_context' => 'array',
        'revision_item_ids' => 'array',
        'revision_amount_due' => 'decimal:2',
        'revision_total' => 'decimal:2',
        'revision_history' => 'array',
        'amount_due' => 'decimal:2',
    ];

    /**
     * Relationship to Booking
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'id');
    }

    /**
     * Scope: Get active (non-expired) payment links
     */
    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope: Get payment links by booking
     */
    public function scopeForBooking($query, string $bookingId)
    {
        return $query->where('booking_id', $bookingId);
    }

    /**
     * Scope: Get unused links (never accessed)
     */
    public function scopeUnused($query)
    {
        return $query->whereNull('accessed_at');
    }

    /**
     * Check if link is still valid (not expired, not invalidated)
     */
    public function isValid(): bool
    {
        return $this->expires_at > now() && is_null($this->invalidated_at);
    }

    /**
     * Get remaining validity time
     */
    public function getRemainingValidity()
    {
        return $this->expires_at->diffForHumans(now());
    }
}
