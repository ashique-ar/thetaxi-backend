<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionHoldRelease extends BaseModel
{
    protected $useUserTracking = false;

    protected $fillable = [
        'company_id', 'commission_decision_id', 'release_kind', 'receipt_finality_event_id',
        'beneficiary_sales_profile_id', 'beneficiary_staff_id',
        'original_hold_code', 'plan_version_id', 'plan_tier_id', 'staff_override_id', 'formula_kind',
        'applied_rate', 'fixed_amount_lkr', 'commission_amount_lkr', 'calculation_explanation',
        'frozen_calculation_snapshot', 'calculation_checksum', 'release_reason', 'released_by',
        'released_at', 'idempotency_key', 'request_payload_checksum',
    ];

    protected $casts = [
        'applied_rate' => 'decimal:6', 'fixed_amount_lkr' => 'decimal:4',
        'commission_amount_lkr' => 'decimal:4', 'frozen_calculation_snapshot' => 'array',
        'released_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Commission hold releases are immutable.'));
        static::deleting(fn () => throw new \LogicException('Commission hold releases cannot be deleted.'));
    }
}
