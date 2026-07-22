<?php
namespace App\Models\Finance;

use App\Models\BaseModel;

class FinancialPaymentAllocation extends BaseModel
{
    protected $fillable = ['settlement_id','settlement_item_id','booking_id','payment_receipt_id','amount','allocated_by','allocated_at'];
    protected $casts = ['amount'=>'decimal:2','allocated_at'=>'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payment allocations are immutable.'));
        static::deleting(fn () => throw new \LogicException('Payment allocations cannot be deleted.'));
    }
}
