<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesFeatureSetting extends BaseModel
{
    protected $table = 'sales_feature_settings';

    protected $fillable = [
        'feature_key',
        'version',
        'enabled',
        'stage_label',
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
