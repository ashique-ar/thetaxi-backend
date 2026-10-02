<?php

namespace App\Models\Hr;

use App\Models\BaseModel;

class HrReportingLine extends BaseModel
{
    protected $fillable = [
        'company_id', 'manager_staff_id', 'member_staff_id', 'line_type',
        'effective_from', 'effective_until', 'status', 'version', 'reason', 'idempotency_key',
        'created_user_id', 'updated_user_id',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_until' => 'date',
        'version' => 'integer',
    ];
}
