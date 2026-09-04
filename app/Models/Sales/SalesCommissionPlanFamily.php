<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionPlanFamily extends BaseModel
{
    protected $fillable = ['company_id', 'code', 'name', 'commission_category', 'status', 'created_by', 'approved_by', 'approved_at'];
    protected $casts = ['approved_at' => 'datetime'];
}
