<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionPlanVersion extends BaseModel
{
    protected $fillable = [
        'plan_family_id', 'version', 'formula_kind', 'eligible_basis', 'percentage_rate', 'fixed_amount_lkr',
        'rounding_mode', 'rounding_scale', 'effective_from', 'effective_until', 'status', 'created_by', 'approved_by', 'approved_at',
    ];
    protected $casts = [
        'version' => 'integer', 'percentage_rate' => 'decimal:6', 'fixed_amount_lkr' => 'decimal:4',
        'rounding_scale' => 'integer', 'effective_from' => 'datetime', 'effective_until' => 'datetime', 'approved_at' => 'datetime',
    ];
}
