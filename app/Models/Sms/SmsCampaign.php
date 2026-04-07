<?php

namespace App\Models\Sms;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsCampaign extends BaseModel
{
    protected $fillable = [
        'name',
        'message',
        'provider',
        'sender_mask',
        'status',
        'audience_type',
        'audience_filters',
        'recipient_snapshot',
        'provider_campaign_id',
        'total_recipients',
        'queued_recipients',
        'sent_recipients',
        'delivered_recipients',
        'failed_recipients',
        'scheduled_at',
        'launched_at',
        'completed_at',
        'meta',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'audience_filters' => 'array',
        'recipient_snapshot' => 'array',
        'meta' => 'array',
        'scheduled_at' => 'datetime',
        'launched_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class, 'campaign_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }
}
