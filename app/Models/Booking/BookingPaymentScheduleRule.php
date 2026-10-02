<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingPaymentScheduleRule extends BaseModel
{
    protected $useUserTracking = false;

    protected $fillable = [
        'company_id', 'booking_id', 'contract_basis', 'frequency', 'frequency_months',
        'anchor_date', 'source_amount', 'source_currency', 'lkr_amount',
        'is_collection_target_eligible', 'reminder_offset_days', 'horizon_months',
        'status', 'version', 'last_generated_occurrence', 'last_generated_through',
        'reason', 'idempotency_key', 'request_payload_checksum', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'frequency_months' => 'integer', 'anchor_date' => 'date', 'source_amount' => 'decimal:4',
        'lkr_amount' => 'decimal:4', 'is_collection_target_eligible' => 'boolean',
        'reminder_offset_days' => 'integer', 'horizon_months' => 'integer', 'version' => 'integer',
        'last_generated_occurrence' => 'integer', 'last_generated_through' => 'date',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingPaymentScheduleRuleEvent::class);
    }
}
