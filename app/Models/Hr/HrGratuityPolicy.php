<?php

namespace App\Models\Hr;

use App\Models\BaseModel;

class HrGratuityPolicy extends BaseModel
{
    protected $table = 'hr_gratuity_policies';

    protected $useUserTracking = false;

    protected $fillable = [
        'company_id', 'version', 'status',
        'minimum_qualifying_service_years', 'minimum_employer_headcount_threshold',
        'monthly_paid_divisor', 'non_monthly_daily_wage_multiplier', 'non_monthly_lookback_months',
        'payment_deadline_days', 'tax_exempt_threshold_lkr', 'tax_rate_above_threshold_percent',
        'statutory_reference', 'effective_from', 'effective_until',
        'reason', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'minimum_qualifying_service_years' => 'integer',
        'minimum_employer_headcount_threshold' => 'integer',
        'monthly_paid_divisor' => 'decimal:2',
        'non_monthly_daily_wage_multiplier' => 'decimal:2',
        'non_monthly_lookback_months' => 'integer',
        'payment_deadline_days' => 'integer',
        'tax_exempt_threshold_lkr' => 'decimal:2',
        'tax_rate_above_threshold_percent' => 'decimal:2',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
        'approved_at' => 'datetime',
    ];
}
