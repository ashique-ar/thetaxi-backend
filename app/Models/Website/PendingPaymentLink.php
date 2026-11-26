<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Traits\RecordsBookingHistoryAtomically;
use Spatie\Activitylog\Support\LogOptions;

class PendingPaymentLink extends BaseModel
{
    use RecordsBookingHistoryAtomically;

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
        'accepted_at',
        'revision_number',
        'acceptance_evidence',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accessed_at' => 'datetime',
        'invalidated_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revision_number' => 'integer',
        'acceptance_evidence' => 'array',
        'booking_context' => 'array',
        'revision_item_ids' => 'array',
        'revision_amount_due' => 'decimal:2',
        'revision_total' => 'decimal:2',
        'revision_history' => 'array',
        'amount_due' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'expires_at', 'invalidated_at', 'accepted_at', 'revision_number'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'created_at'])
            ->useLogName('PendingPaymentLink');
    }

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

    /** Decode booking_context safely, including legacy double-encoded JSON rows. */
    public function decodedBookingContext(): array
    {
        $context = $this->booking_context;
        if (!is_array($context)) {
            $context = json_decode((string) $context, true) ?: [];
        }

        return is_array($context) ? $context : [];
    }

    /**
     * Get remaining validity time
     */
    public function getRemainingValidity()
    {
        return $this->expires_at->diffForHumans(now());
    }
}
