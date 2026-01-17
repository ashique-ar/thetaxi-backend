<?php

namespace App\Models;

use App\Models\BaseModel;

class PaymentEvent extends BaseModel
{
    protected $table = 'payment_events';

    protected $fillable = [
        'booking_id',
        'booking_number',
        'event_type',
        'source',
        'transaction_id',
        'status',
        'payload',
        'message',
        'created_user_id'
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
