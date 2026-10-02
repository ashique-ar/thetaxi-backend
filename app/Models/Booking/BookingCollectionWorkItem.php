<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingCollectionWorkItem extends BaseModel
{
    protected $fillable = [
        'company_id', 'booking_id', 'booking_payment_schedule_id', 'assigned_sales_profile_id',
        'work_type', 'status', 'due_at', 'reminder_offset_days', 'last_reminded_at',
        'completed_at', 'idempotency_key',
    ];

    protected $casts = [
        'due_at' => 'datetime', 'last_reminded_at' => 'datetime', 'completed_at' => 'datetime',
        'reminder_offset_days' => 'integer',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function paymentSchedule(): BelongsTo { return $this->belongsTo(BookingPaymentSchedule::class, 'booking_payment_schedule_id'); }
}
