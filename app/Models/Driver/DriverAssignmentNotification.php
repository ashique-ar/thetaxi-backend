<?php

namespace App\Models\Driver;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DriverAssignmentNotification extends Model
{
    use HasUuids;

    protected $fillable = [
        'assignment_id', 'driver_id', 'database_notification_id', 'delivered_at',
        'delivery_channel', 'acknowledged_at', 'acknowledgement_source',
        'fallback_due_at', 'fallback_checked_at', 'fallback_result',
        'fallback_sms_message_id', 'meta',
    ];

    protected $casts = [
        'delivered_at' => 'datetime', 'acknowledged_at' => 'datetime',
        'fallback_due_at' => 'datetime', 'fallback_checked_at' => 'datetime',
        'meta' => 'array',
    ];
}
