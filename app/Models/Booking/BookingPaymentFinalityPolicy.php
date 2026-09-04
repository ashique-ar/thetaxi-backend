<?php

namespace App\Models\Booking;

use App\Models\BaseModel;

class BookingPaymentFinalityPolicy extends BaseModel
{
    protected $fillable = [
        'company_id', 'payment_method', 'official_collection_state', 'can_earn_before_final',
        'hold_payout_until_final', 'clearance_timeout_hours', 'required_evidence_type',
        'effective_from', 'effective_until', 'version', 'status', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'can_earn_before_final' => 'boolean', 'hold_payout_until_final' => 'boolean',
        'clearance_timeout_hours' => 'integer', 'effective_from' => 'datetime', 'effective_until' => 'datetime',
        'version' => 'integer', 'approved_at' => 'datetime',
    ];
}
