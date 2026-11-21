<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Traits\RecordsBookingHistoryAtomically;

class BookingPaymentReceiptFinalityEvent extends BaseModel
{
    use RecordsBookingHistoryAtomically;

    protected function bookingHistoryConnectionName(): ?string
    {
        return $this->getConnectionName() ?? (new BookingPaymentReceipt)->getConnectionName();
    }
    protected $fillable = [
        'booking_id', 'booking_payment_receipt_id', 'finality_policy_id', 'from_status', 'to_status',
        'reason', 'evidence_reference', 'idempotency_key', 'performed_by', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Receipt-finality events are immutable.'));
        static::deleting(fn () => throw new \LogicException('Receipt-finality events cannot be deleted.'));
    }
}
