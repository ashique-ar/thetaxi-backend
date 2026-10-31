<?php

namespace App\Models\Sales;

use App\Models\NonSoftDeletableModel;

class SalesCommissionAccountingDelivery extends NonSoftDeletableModel
{
    protected $hidden = ['request_payload_checksum'];
    protected $fillable = [
        'payout_id', 'event_type', 'status', 'external_reference', 'message', 'idempotency_key',
        'request_payload_checksum', 'verification_source', 'source_outbox_event_id', 'provider_transaction_id',
        'provider_response_checksum', 'recorded_by', 'recorded_at',
    ];
    protected $casts = ['recorded_at' => 'datetime'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Accounting delivery acknowledgements are immutable.'));
        static::deleting(fn () => throw new \LogicException('Accounting delivery acknowledgements cannot be deleted.'));
    }
}
