<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionHoldResolution extends BaseModel
{
    protected $useUserTracking = false;

    protected $fillable = [
        'company_id', 'commission_decision_id', 'receipt_finality_event_id',
        'employment_staff_id', 'employment_ended_at', 'employment_terminated_by', 'resolution_kind',
        'original_hold_code', 'frozen_resolution_snapshot', 'resolution_checksum', 'resolution_reason',
        'resolved_by', 'resolved_at', 'idempotency_key', 'request_payload_checksum',
    ];

    protected $casts = [
        'frozen_resolution_snapshot' => 'array',
        'employment_ended_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Commission hold resolutions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Commission hold resolutions cannot be deleted.'));
    }
}
