<?php

namespace App\Models\Sales;

use App\Models\NonSoftDeletableModel;

class SalesCommissionPlanFamily extends NonSoftDeletableModel
{
    protected $fillable = ['company_id', 'code', 'name', 'commission_category', 'status', 'created_by', 'approved_by', 'approved_at'];
    protected $casts = ['approved_at' => 'datetime'];
}
