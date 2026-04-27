<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverAssignmentStop extends BaseModel
{
    protected $table = 'driver_assignment_stops';

    protected $fillable = [
        'assignment_id',
        'booking_id',
        'booking_item_id',
        'booking_stop_id',
        'stop_type',
        'route_order',
        'type_sequence',
        'status',
        'location',
        'label',
        'address',
        'latitude',
        'longitude',
        'arrived_at',
        'arrived_latitude',
        'arrived_longitude',
        'completed_at',
        'completed_latitude',
        'completed_longitude',
        'completed_action',
        'skip_reason',
        'notes',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'location' => 'array',
        'route_order' => 'integer',
        'type_sequence' => 'integer',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'arrived_at' => 'datetime',
        'arrived_latitude' => 'decimal:8',
        'arrived_longitude' => 'decimal:8',
        'completed_at' => 'datetime',
        'completed_latitude' => 'decimal:8',
        'completed_longitude' => 'decimal:8',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DriverAssignment::class, 'assignment_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['picked_up', 'dropped_off', 'skipped'], true);
    }
}
