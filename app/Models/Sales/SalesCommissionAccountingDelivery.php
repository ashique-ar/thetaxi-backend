<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionAccountingDelivery extends BaseModel
{
    protected $fillable = ['company_id', 'payout_id', 'event_type', 'status', 'external_reference', 'message', 'idempotency_key', 'recorded_by', 'recorded_at'];
    protected $casts = ['recorded_at' => 'datetime'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Accounting delivery acknowledgements are immutable.'));
        static::deleting(fn () => throw new \LogicException('Accounting delivery acknowledgements cannot be deleted.'));
    }
}
