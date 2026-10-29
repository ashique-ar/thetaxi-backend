<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InquiryOutboundResponse extends BaseModel
{
    protected $table = 'inquiry_outbound_responses';

    protected $fillable = [
        'company_id',
        'inquiry_id',
        'sent_by_user_id',
        'idempotency_key',
        'recipient_email',
        'message',
        'status',
        'failure_reason',
        'sent_at',
    ];

    protected $casts = [
        'recipient_email' => 'encrypted',
        'message' => 'encrypted',
        'sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (InquiryOutboundResponse $response): void {
            $response->id ??= (string) Str::uuid();
        });
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id')->withTrashed();
    }
}
