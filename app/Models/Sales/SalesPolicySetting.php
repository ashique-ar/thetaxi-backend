<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesPolicySetting extends BaseModel
{
    protected $fillable = [
        'company_id',
        'policy_kind',
        'version',
        'status',
        'fx_quote_base',
        'fx_calculation_mode',
        'fx_max_rate_age_hours',
        'fx_rounding_scale',
        'dispute_response_days',
        'profile_export_retention_days',
        'business_timezone',
        'reason',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'fx_max_rate_age_hours' => 'integer',
        'fx_rounding_scale' => 'integer',
        'dispute_response_days' => 'integer',
        'profile_export_retention_days' => 'integer',
        'approved_at' => 'datetime',
    ];
}
