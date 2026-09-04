<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionStaffOverride extends BaseModel
{
    protected $fillable = [
        'company_id', 'staff_id', 'percentage_rate', 'effective_from', 'effective_until',
        'reason', 'status', 'created_by', 'approved_by', 'approved_at',
    ];
    protected $casts = [
        'percentage_rate' => 'decimal:6', 'effective_from' => 'datetime', 'effective_until' => 'datetime', 'approved_at' => 'datetime',
    ];
}
