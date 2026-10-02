<?php

namespace App\Models\Booking;

use App\Models\BaseModel;

class BookingCommercialValueAdjustment extends BaseModel
{
    protected $fillable = [
        'company_id', 'booking_id', 'attribution_id', 'acquisition_sales_profile_id',
        'adjustment_type', 'delta_source_amount', 'source_currency', 'delta_lkr_amount',
        'fx_rate_to_lkr', 'fx_rate_at', 'previous_effective_source_amount', 'previous_effective_lkr_amount',
        'resulting_effective_source_amount', 'resulting_effective_lkr_amount', 'counts_as_new_sales_adjustment',
        'schedule_revision_id', 'effective_at', 'reason', 'idempotency_key', 'request_payload_checksum',
        'approved_by', 'approved_at', 'created_user_id',
    ];

    protected $casts = [
        'delta_source_amount' => 'decimal:4', 'delta_lkr_amount' => 'decimal:4', 'fx_rate_to_lkr' => 'decimal:10',
        'previous_effective_source_amount' => 'decimal:4', 'previous_effective_lkr_amount' => 'decimal:4',
        'resulting_effective_source_amount' => 'decimal:4', 'resulting_effective_lkr_amount' => 'decimal:4',
        'counts_as_new_sales_adjustment' => 'boolean', 'fx_rate_at' => 'datetime',
        'effective_at' => 'datetime', 'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Commercial value adjustments are immutable; create a further dated adjustment.'));
        static::deleting(fn () => throw new \LogicException('Commercial value adjustments cannot be deleted.'));
    }
}
