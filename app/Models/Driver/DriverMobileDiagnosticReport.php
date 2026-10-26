<?php

namespace App\Models\Driver;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverMobileDiagnosticReport extends BaseModel
{
    protected $table = 'driver_mobile_diagnostic_reports';

    protected $fillable = [
        'report_id',
        'driver_id',
        'severity',
        'category',
        'source',
        'fingerprint',
        'error_type',
        'error_summary',
        'context',
        'events',
        'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'events' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
