<?php

namespace App\Models\Driver;

use App\Models\NonSoftDeletableTrackedModel;
use App\Traits\RecordsBookingHistoryAtomically;

class DriverTrackingIncident extends NonSoftDeletableTrackedModel
{
    use RecordsBookingHistoryAtomically;

    protected $table = 'driver_tracking_incidents';

    protected $fillable = [
        'incident_id', 'driver_id', 'assignment_id', 'booking_id', 'booking_item_id',
        'state', 'cause', 'started_at', 'reported_at', 'recovered_at',
        'first_evidence', 'last_evidence',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'reported_at' => 'datetime',
        'recovered_at' => 'datetime',
        'first_evidence' => 'array',
        'last_evidence' => 'array',
    ];
}
