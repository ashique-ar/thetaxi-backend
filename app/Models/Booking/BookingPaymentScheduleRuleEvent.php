<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPaymentScheduleRuleEvent extends BaseModel
{
    protected $useUserTracking = false;

    protected $fillable = [
        'booking_payment_schedule_rule_id', 'version', 'event_type', 'from_status', 'to_status',
        'effective_at', 'reason', 'metadata', 'idempotency_key', 'request_payload_checksum',
        'event_checksum', 'actor_type', 'actor_user_id',
    ];

    protected $casts = ['version' => 'integer', 'effective_at' => 'datetime', 'metadata' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payment schedule rule events are immutable.'));
        static::deleting(fn () => throw new \LogicException('Payment schedule rule events cannot be deleted.'));
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(BookingPaymentScheduleRule::class, 'booking_payment_schedule_rule_id');
    }
}
