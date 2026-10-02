<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionStatementAdjustment extends BaseModel
{
    protected $fillable = ['company_id', 'staff_id', 'dispute_id', 'amount_lkr', 'reason', 'approved_by', 'approved_at', 'idempotency_key'];
    protected $casts = ['amount_lkr' => 'decimal:4', 'approved_at' => 'datetime'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Commission statement adjustments are immutable.'));
        static::deleting(fn () => throw new \LogicException('Commission statement adjustments cannot be deleted.'));
    }
}
