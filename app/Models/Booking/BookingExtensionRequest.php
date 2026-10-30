<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingExtensionRequest extends BaseModel
{
    protected $fillable = [
        'booking_id', 'booking_item_id', 'current_end_at', 'requested_end_at',
        'reason', 'notes', 'status', 'additional_charge', 'charge_currency', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected $casts = [
        'current_end_at' => 'datetime',
        'requested_end_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'additional_charge' => 'decimal:2',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function bookingItem(): BelongsTo { return $this->belongsTo(BookingItem::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
