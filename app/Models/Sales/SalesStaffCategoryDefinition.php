<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesStaffCategoryDefinition extends BaseModel
{
    protected $fillable = [
        'company_id',
        'category_name',
        'status',
        'reason',
        'created_by',
        'approved_by',
        'approved_at',
        'retired_by',
        'retired_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'retired_at' => 'datetime',
    ];
}
