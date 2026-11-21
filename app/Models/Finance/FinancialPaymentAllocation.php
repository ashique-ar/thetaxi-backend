<?php
namespace App\Models\Finance;

use App\Models\BaseModel;
use App\Traits\RecordsBookingHistoryAtomically;

class FinancialPaymentAllocation extends BaseModel
{
    use RecordsBookingHistoryAtomically;
    protected $fillable = ['settlement_id','settlement_item_id','booking_id','payment_receipt_id','amount','allocated_by','allocated_at'];
    protected $casts = ['amount'=>'decimal:4','allocated_at'=>'datetime'];

    public function receipt()
    {
        return $this->belongsTo(\App\Models\Booking\BookingPaymentReceipt::class, 'payment_receipt_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payment allocations are immutable.'));
        static::deleting(fn () => throw new \LogicException('Payment allocations cannot be deleted.'));
    }
}
