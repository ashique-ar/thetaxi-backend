<?php

namespace App\Models\Hr;

use App\Models\NonSoftDeletableTrackedModel;
use Spatie\Activitylog\Support\LogOptions;

class HrReportingLine extends NonSoftDeletableTrackedModel
{
    protected $fillable = [
        'company_id', 'manager_staff_id', 'member_staff_id', 'line_type',
        'effective_from', 'effective_until', 'status', 'version', 'reason', 'idempotency_key',
        'created_user_id', 'updated_user_id',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_until' => 'date',
        'version' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['company_id', 'line_type', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'created_at'])
            ->useLogName('HrReportingLine');
    }
}
