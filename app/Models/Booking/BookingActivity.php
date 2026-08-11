<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BookingActivity extends BaseModel
{

    protected $fillable = [
        'booking_id', 'booking_item_id', 'driver_assignment_id', 'inquiry_id',
        'sms_message_id', 'event_key', 'channel', 'result_status', 'source',
        'recipient_masked', 'title', 'detail', 'idempotency_key', 'meta', 'event_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'event_at' => 'datetime',
    ];
}
