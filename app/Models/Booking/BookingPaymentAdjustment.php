<?php

namespace App\Models\Booking;

use App\Models\BaseModel;

class BookingPaymentAdjustment extends BaseModel
{
    protected $fillable = [
        'company_id', 'booking_id', 'receipt_id', 'receipt_component_id', 'impact_dimension',
        'adjustment_type', 'direction', 'source_amount', 'source_currency', 'lkr_amount', 'fx_rate_to_lkr',
        'fx_rate_at', 'fx_source', 'fx_quote_base', 'fx_calculation_mode', 'original_lkr_amount', 'original_fx_rate_to_lkr',
        'original_fx_rate_at', 'original_fx_source', 'lkr_delta', 'cumulative_lkr_delta', 'commission_decision_id',
        'corrects_adjustment_id', 'lineage_root_adjustment_id', 'correction_sequence', 'prior_corrected_lkr_amount',
        'prior_fx_rate_to_lkr', 'prior_fx_rate_at', 'prior_fx_source',
        'adjustment_effective_at', 'reason', 'reference', 'idempotency_key', 'request_payload_checksum', 'preview_checksum',
        'approved_by', 'approved_at', 'created_user_id',
    ];

    protected $casts = [
        'source_amount' => 'decimal:4', 'lkr_amount' => 'decimal:4', 'fx_rate_to_lkr' => 'decimal:10',
        'original_lkr_amount' => 'decimal:4', 'original_fx_rate_to_lkr' => 'decimal:10', 'lkr_delta' => 'decimal:4',
        'cumulative_lkr_delta' => 'decimal:4', 'prior_corrected_lkr_amount' => 'decimal:4',
        'prior_fx_rate_to_lkr' => 'decimal:10', 'correction_sequence' => 'integer',
        'fx_rate_at' => 'datetime', 'original_fx_rate_at' => 'datetime', 'prior_fx_rate_at' => 'datetime',
        'adjustment_effective_at' => 'datetime', 'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payment adjustments are immutable; create a counter-adjustment.'));
        static::deleting(fn () => throw new \LogicException('Payment adjustments cannot be deleted; create a counter-adjustment.'));
    }
}
