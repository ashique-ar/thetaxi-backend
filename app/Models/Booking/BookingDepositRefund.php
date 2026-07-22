<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingDepositRefund extends BaseModel
{
    protected $fillable = ['booking_payment_receipt_id', 'booking_id', 'amount', 'refund_method', 'reference', 'refunded_at', 'refunded_by', 'notes'];
    protected $casts = ['amount' => 'decimal:2', 'refunded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Deposit refund evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Deposit refund evidence cannot be deleted.'));
    }

    public function receipt(): BelongsTo { return $this->belongsTo(BookingPaymentReceipt::class, 'booking_payment_receipt_id'); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
}
