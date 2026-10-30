<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesPolicySetting extends BaseModel
{
    protected $fillable = [
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
        'inquiry_first_response_minutes',
        'inquiry_response_business_hours',
        'booking_assignment_target_minutes',
        'repeat_booking_followup_enabled',
        'repeat_booking_followup_days',
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
        'inquiry_first_response_minutes' => 'integer',
        'inquiry_response_business_hours' => 'array',
        'booking_assignment_target_minutes' => 'integer',
        'repeat_booking_followup_enabled' => 'boolean',
        'repeat_booking_followup_days' => 'integer',
        'approved_at' => 'datetime',
    ];
}
