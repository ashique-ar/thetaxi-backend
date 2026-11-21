<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Traits\RecordsBookingHistoryAtomically;

class BookingCollectionReminderDelivery extends BaseModel
{
    use RecordsBookingHistoryAtomically;

    protected $table = 'booking_collection_reminder_deliveries';

    protected $fillable = [
        'booking_id', 'booking_payment_schedule_id', 'booking_collection_work_item_id',
        'assigned_sales_profile_id', 'recipient_user_id', 'reminder_type', 'channel',
        'schedule_due_date', 'outstanding_amount', 'source_currency', 'status',
        'database_notification_id', 'dispatched_at', 'idempotency_key', 'payload_checksum',
    ];

    protected $casts = [
        'schedule_due_date' => 'date',
        'outstanding_amount' => 'decimal:4',
        'dispatched_at' => 'datetime',
    ];
}
