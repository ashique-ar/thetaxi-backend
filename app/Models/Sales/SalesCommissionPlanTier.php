<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionPlanTier extends BaseModel
{
    protected $fillable = [
        'plan_version_id', 'sequence', 'minimum_lkr', 'maximum_lkr', 'minimum_inclusive',
        'maximum_inclusive', 'percentage_rate',
    ];
    protected $casts = [
        'sequence' => 'integer', 'minimum_lkr' => 'decimal:4', 'maximum_lkr' => 'decimal:4',
        'minimum_inclusive' => 'boolean', 'maximum_inclusive' => 'boolean', 'percentage_rate' => 'decimal:6',
    ];
}
