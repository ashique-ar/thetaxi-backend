<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPaymentScheduleAllocation extends BaseModel
{
    protected $fillable = [
        'booking_payment_schedule_id', 'booking_payment_receipt_id',
        'amount', 'allocated_at', 'allocated_by',
    ];
    protected $casts = ['amount' => 'decimal:2', 'allocated_at' => 'datetime'];
    public function schedule(): BelongsTo { return $this->belongsTo(BookingPaymentSchedule::class, 'booking_payment_schedule_id'); }
    public function receipt(): BelongsTo { return $this->belongsTo(BookingPaymentReceipt::class, 'booking_payment_receipt_id'); }
}
