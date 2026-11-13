<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CorporateReportDeliveryRun extends BaseModel
{
    use HasUuids;

    protected $useUserTracking = false;

    protected $fillable = [
        'corporate_id', 'schedule_id', 'schedule_owner_user_id', 'schedule_name', 'format',
        'period_start', 'period_end', 'filter_snapshot', 'recipient_snapshot', 'status',
        'started_at', 'finished_at', 'row_count', 'artifact_bytes', 'artifact_sha256', 'error_summary',
    ];

    protected $casts = [
        'filter_snapshot' => 'array',
        'recipient_snapshot' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
