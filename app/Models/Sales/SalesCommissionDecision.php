<?php

namespace App\Models\Sales;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesCommissionDecision extends BaseModel
{
    protected $fillable = [
        'company_id', 'booking_id', 'root_attribution_id', 'booking_attribution_id', 'receipt_id',
        'receipt_component_id', 'beneficiary_sales_profile_id', 'beneficiary_staff_id',
        'acquisition_sales_profile_id', 'collection_sales_profile_id', 'commission_category', 'collection_cohort',
        'eligible_source_amount', 'source_currency', 'fx_rate_to_lkr', 'fx_rate_at', 'fx_source', 'eligible_lkr_amount',
        'plan_family_id', 'plan_assignment_id', 'plan_version_id', 'plan_tier_id', 'staff_override_id',
        'formula_kind', 'applied_rate', 'fixed_amount_lkr', 'commission_amount_lkr', 'status', 'hold_code',
        'calculation_explanation', 'receipt_finality_status', 'finality_policy_id',
        'can_earn_before_final_snapshot', 'hold_payout_until_final_snapshot',
        'rounding_mode_snapshot', 'rounding_scale_snapshot',
        'earned_at', 'decision_at', 'event_version', 'idempotency_key',
    ];
    protected $casts = [
        'eligible_source_amount' => 'decimal:4', 'fx_rate_to_lkr' => 'decimal:10', 'fx_rate_at' => 'datetime',
        'eligible_lkr_amount' => 'decimal:4', 'applied_rate' => 'decimal:6', 'fixed_amount_lkr' => 'decimal:4',
        'commission_amount_lkr' => 'decimal:4', 'can_earn_before_final_snapshot' => 'boolean',
        'hold_payout_until_final_snapshot' => 'boolean', 'rounding_scale_snapshot' => 'integer', 'earned_at' => 'datetime',
        'decision_at' => 'datetime', 'event_version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Commission decisions are immutable; create a linked adjustment.'));
        static::deleting(fn () => throw new \LogicException('Commission decisions cannot be deleted.'));
    }

    public function holdRelease(): HasOne
    {
        return $this->hasOne(SalesCommissionHoldRelease::class, 'commission_decision_id');
    }

    public function holdAdjustment(): HasOne
    {
        return $this->hasOne(SalesCommissionHoldAdjustment::class, 'commission_decision_id');
    }

    public function holdResolution(): HasOne
    {
        return $this->hasOne(SalesCommissionHoldResolution::class, 'commission_decision_id');
    }
}
