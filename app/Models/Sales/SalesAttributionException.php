<?php

namespace App\Models\Sales;

use App\Models\NonSoftDeletableTrackedModel;
use App\Traits\RecordsBookingHistoryAtomically;

class SalesAttributionException extends NonSoftDeletableTrackedModel
{
    use RecordsBookingHistoryAtomically;

    protected $table = 'sales_attribution_exceptions';

    protected $fillable = [
        'booking_id', 'exception_type', 'status', 'details', 'detected_at',
        'created_user_id', 'updated_user_id', 'resolved_by', 'resolved_at', 'resolution',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
