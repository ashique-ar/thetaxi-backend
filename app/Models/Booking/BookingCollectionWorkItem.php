<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Traits\RecordsBookingHistoryAtomically;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BookingCollectionWorkItem extends BaseModel
{
    use RecordsBookingHistoryAtomically;
    protected $fillable = [
        'booking_id', 'booking_payment_schedule_id', 'assigned_sales_profile_id',
        'work_type', 'status', 'due_at', 'reminder_offset_days', 'last_reminded_at',
        'completed_at', 'idempotency_key',
    ];

    protected $casts = [
        'due_at' => 'datetime', 'last_reminded_at' => 'datetime', 'completed_at' => 'datetime',
        'reminder_offset_days' => 'integer',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function paymentSchedule(): BelongsTo { return $this->belongsTo(BookingPaymentSchedule::class, 'booking_payment_schedule_id'); }
    public function latestFollowUp(): HasOne { return $this->hasOne(BookingCollectionWorkItemFollowUp::class, 'booking_collection_work_item_id')->latestOfMany('occurred_at'); }
}
