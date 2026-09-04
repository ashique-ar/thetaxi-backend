<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CorporateReportSchedule extends BaseModel
{
    use HasUuids;

    protected $useUserTracking = false;

    protected $fillable = ['corporate_id', 'name', 'format', 'delivery_day', 'recipients', 'filters', 'is_active', 'last_delivered_at', 'last_failed_at', 'last_error', 'created_by'];
    protected $casts = ['recipients' => 'array', 'filters' => 'array', 'is_active' => 'boolean', 'last_delivered_at' => 'datetime', 'last_failed_at' => 'datetime'];
}
