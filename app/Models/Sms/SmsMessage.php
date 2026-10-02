<?php

namespace App\Models\Sms;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends BaseModel
{
    protected $fillable = [
        'campaign_id',
        'provider',
        'channel',
        'source',
        'context_type',
        'context_id',
        'booking_id',
        'booking_item_id',
        'driver_assignment_id',
        'inquiry_id',
        'template_key',
        'event_key',
        'idempotency_key',
        'recipient',
        'normalized_recipient',
        'sender_mask',
        'message',
        'status',
        'provider_status',
        'provider_status_at',
        'segments',
        'unit_cost',
        'total_cost',
        'cost_currency',
        'provider_message_id',
        'provider_campaign_id',
        'provider_transaction_id',
        'attempts',
        'error_message',
        'provider_response',
        'meta',
        'queued_at',
        'processing_at',
        'sent_at',
        'delivered_at',
        'failed_at',
        'scheduled_at',
        'triggered_at',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'provider_response' => 'array',
        'meta' => 'array',
        'queued_at' => 'datetime',
        'processing_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'triggered_at' => 'datetime',
        'provider_status_at' => 'datetime',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SmsCampaign::class, 'campaign_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function bookingItem(): BelongsTo
    {
        return $this->belongsTo(BookingItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }
}
