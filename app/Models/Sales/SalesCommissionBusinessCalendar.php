<?php

namespace App\Models\Sales;

use App\Models\NonSoftDeletableModel;

class SalesCommissionBusinessCalendar extends NonSoftDeletableModel
{
    protected $fillable = [
        'company_id', 'code', 'name', 'timezone', 'weekly_working_days', 'effective_from',
        'effective_until', 'status', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'weekly_working_days' => 'array', 'effective_from' => 'date', 'effective_until' => 'date',
        'approved_at' => 'datetime',
    ];
}
