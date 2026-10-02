<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionCycleVersion extends BaseModel
{
    protected $fillable = [
        'company_id', 'code', 'version', 'timezone', 'business_calendar_id', 'earning_period_rule', 'cutoff_day',
        'finalization_day', 'approval_deadline_day', 'settlement_day', 'holiday_rule',
        'payout_currency', 'effective_from', 'effective_until', 'status', 'created_by', 'approved_by', 'approved_at',
    ];
    protected $casts = [
        'version' => 'integer', 'cutoff_day' => 'integer', 'finalization_day' => 'integer',
        'approval_deadline_day' => 'integer', 'settlement_day' => 'integer',
        'effective_from' => 'datetime', 'effective_until' => 'datetime', 'approved_at' => 'datetime',
    ];
}
