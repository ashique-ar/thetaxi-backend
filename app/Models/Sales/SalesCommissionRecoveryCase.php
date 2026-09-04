<?php

namespace App\Models\Sales;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesCommissionRecoveryCase extends BaseModel
{
    protected $fillable = [
        'company_id', 'commission_decision_id', 'entitlement_source_type', 'entitlement_source_id',
        'entitlement_amount_lkr', 'entitlement_effective_at', 'payment_adjustment_id',
        'corrects_recovery_case_id', 'beneficiary_staff_id',
        'beneficiary_sales_profile_id', 'recovery_kind', 'commission_category', 'collection_cohort',
        'adjusted_source_amount', 'source_currency', 'original_eligible_lkr_amount', 'original_fx_rate_to_lkr',
        'original_fx_rate_at', 'original_fx_source', 'corrected_lkr_amount', 'corrected_fx_rate_to_lkr',
        'corrected_fx_rate_at', 'corrected_fx_source', 'corrected_fx_calculation_mode', 'reporting_lkr_delta',
        'corrected_eligible_lkr_amount', 'recalculated_plan_tier_id', 'recalculated_rate',
        'recalculated_tier_sequence', 'recalculated_tier_minimum_lkr', 'recalculated_tier_maximum_lkr',
        'recalculated_tier_minimum_inclusive', 'recalculated_tier_maximum_inclusive',
        'recalculated_commission_amount_lkr', 'original_formula_kind',
        'original_applied_rate', 'original_fixed_amount_lkr', 'original_commission_amount_lkr',
        'prior_recalculated_commission_amount_lkr',
        'proposed_commission_adjustment_lkr', 'proposed_recovery_lkr', 'calculation_status',
        'calculation_explanation', 'calculation_checksum', 'status', 'event_version', 'opened_at', 'resolved_at',
    ];
    protected $casts = [
        'entitlement_amount_lkr' => 'decimal:4', 'entitlement_effective_at' => 'datetime',
        'adjusted_source_amount' => 'decimal:4', 'proposed_recovery_lkr' => 'decimal:4',
        'original_eligible_lkr_amount' => 'decimal:4', 'original_fx_rate_to_lkr' => 'decimal:10',
        'corrected_lkr_amount' => 'decimal:4', 'corrected_fx_rate_to_lkr' => 'decimal:10',
        'reporting_lkr_delta' => 'decimal:4', 'original_applied_rate' => 'decimal:6',
        'corrected_eligible_lkr_amount' => 'decimal:4', 'recalculated_rate' => 'decimal:6',
        'recalculated_tier_sequence' => 'integer', 'recalculated_tier_minimum_lkr' => 'decimal:4',
        'recalculated_tier_maximum_lkr' => 'decimal:4', 'recalculated_tier_minimum_inclusive' => 'boolean',
        'recalculated_tier_maximum_inclusive' => 'boolean',
        'recalculated_commission_amount_lkr' => 'decimal:4',
        'original_fixed_amount_lkr' => 'decimal:4', 'original_commission_amount_lkr' => 'decimal:4',
        'prior_recalculated_commission_amount_lkr' => 'decimal:4',
        'proposed_commission_adjustment_lkr' => 'decimal:4',
        'original_fx_rate_at' => 'datetime', 'corrected_fx_rate_at' => 'datetime',
        'event_version' => 'integer', 'opened_at' => 'datetime', 'resolved_at' => 'datetime',
    ];

    public function decision(): HasOne
    {
        return $this->hasOne(SalesCommissionRecoveryDecision::class, 'recovery_case_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $case): void {
            $mutable = ['status', 'event_version', 'resolved_at', 'updated_at'];
            if (array_diff(array_keys($case->getDirty()), $mutable) !== []) {
                throw new \LogicException('Commission recovery evidence is immutable; only workflow state may change.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Commission recovery cases cannot be deleted.'));
    }
}
