<?php

namespace App\Models\Booking;

use App\Models\NonSoftDeletableModel;
use App\Traits\RecordsBookingHistoryAtomically;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingCollectionWorkItemFollowUp extends NonSoftDeletableModel
{
    use RecordsBookingHistoryAtomically;

    protected $fillable = [
        'booking_collection_work_item_id', 'booking_id', 'assigned_sales_profile_id', 'actor_user_id',
        'promised_payment_date', 'next_follow_up_at', 'notes', 'idempotency_key',
        'request_payload_checksum', 'occurred_at', 'change_reason',
    ];

    protected $casts = ['promised_payment_date' => 'date', 'next_follow_up_at' => 'datetime', 'occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Collection follow-up records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Collection follow-up records cannot be deleted.'));
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(BookingCollectionWorkItem::class, 'booking_collection_work_item_id');
    }
}
