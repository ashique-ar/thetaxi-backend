<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionCycleAssignment extends BaseModel
{
    protected $fillable = [
        'company_id', 'cycle_version_id', 'scope_type', 'sales_profile_id', 'staff_id',
        'staff_category', 'precedence', 'effective_from', 'effective_until', 'status', 'created_by', 'approved_by', 'approved_at',
    ];
    protected $casts = [
        'precedence' => 'integer', 'effective_from' => 'datetime', 'effective_until' => 'datetime', 'approved_at' => 'datetime',
    ];
}
