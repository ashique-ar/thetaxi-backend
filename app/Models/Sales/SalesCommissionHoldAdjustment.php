<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionHoldAdjustment extends BaseModel
{
    protected $useUserTracking = false;

    protected $fillable = [
        'company_id', 'commission_decision_id', 'source_attribution_id', 'source_attribution_event_id',
        'beneficiary_attribution_event_id',
        'finality_policy_id', 'receipt_finality_event_id',
        'adjustment_kind', 'original_hold_code', 'beneficiary_sales_profile_id', 'beneficiary_staff_id',
        'plan_family_id', 'plan_assignment_id', 'plan_version_id', 'plan_tier_id', 'staff_override_id',
        'formula_kind', 'applied_rate', 'fixed_amount_lkr', 'eligible_lkr_amount', 'commission_adjustment_lkr',
        'frozen_adjustment_snapshot', 'calculation_checksum', 'reason', 'source_prepared_by', 'approved_by',
        'adjustment_effective_at', 'idempotency_key', 'request_payload_checksum',
    ];

    protected $casts = [
        'applied_rate' => 'decimal:6', 'fixed_amount_lkr' => 'decimal:4',
        'eligible_lkr_amount' => 'decimal:4', 'commission_adjustment_lkr' => 'decimal:4',
        'frozen_adjustment_snapshot' => 'array', 'adjustment_effective_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Commission hold adjustments are immutable.'));
        static::deleting(fn () => throw new \LogicException('Commission hold adjustments cannot be deleted.'));
    }
}
