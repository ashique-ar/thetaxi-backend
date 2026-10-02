<?php

namespace App\Models\Booking;

use App\Models\BaseModel;

class BookingPaymentScheduleRevision extends BaseModel
{
    protected $useUserTracking = false;

    protected $fillable = [
        'company_id', 'booking_id', 'revision_number', 'effective_at', 'contract_basis',
        'reconciliation_rule', 'contractual_source_amount', 'source_currency', 'retained_source_amount',
        'replacement_source_amount', 'reconciliation_amount', 'preview_checksum', 'before_snapshot',
        'contractual_lkr_amount', 'retained_lkr_amount', 'replacement_lkr_amount',
        'after_snapshot', 'reason', 'idempotency_key', 'request_payload_checksum', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'revision_number' => 'integer', 'effective_at' => 'datetime', 'before_snapshot' => 'array',
        'after_snapshot' => 'array', 'approved_at' => 'datetime',
        'contractual_source_amount' => 'decimal:4', 'retained_source_amount' => 'decimal:4',
        'replacement_source_amount' => 'decimal:4', 'reconciliation_amount' => 'decimal:4',
        'contractual_lkr_amount' => 'decimal:4', 'retained_lkr_amount' => 'decimal:4',
        'replacement_lkr_amount' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Schedule revisions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Schedule revisions cannot be deleted.'));
    }
}
