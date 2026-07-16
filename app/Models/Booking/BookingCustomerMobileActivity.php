<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingCustomerMobileActivity extends BaseModel
{
    protected $useUserTracking = false;

    protected $fillable = [
        'booking_id',
        'booking_item_id',
        'customer_id',
        'user_id',
        'client_event_id',
        'event_type',
        'occurred_at',
        'actual_start_time',
        'actual_return_time',
        'distance_km',
        'waiting_minutes',
        'payload_hash',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'actual_start_time' => 'datetime',
        'actual_return_time' => 'datetime',
        'distance_km' => 'decimal:3',
        'waiting_minutes' => 'integer',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function bookingItem(): BelongsTo
    {
        return $this->belongsTo(BookingItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
