<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCompanyFeatureSetting extends BaseModel
{
    protected $fillable = [
        'company_id',
        'feature_key',
        'version',
        'enabled',
        'status',
        'reason',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'enabled' => 'boolean',
        'approved_at' => 'datetime',
    ];
}
