<?php

namespace App\Models\Sms;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends BaseModel
{
    protected $fillable = [
        'campaign_id',
        'provider',
        'channel',
        'context_type',
        'context_id',
        'template_key',
        'recipient',
        'normalized_recipient',
        'sender_mask',
        'message',
        'status',
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
        'is_active' => 'boolean',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SmsCampaign::class, 'campaign_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }
}
