<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WaitingTimeRecord Model
 *
 * Tracks stationary periods during an active trip where the vehicle
 * speed is below 3 km/h for 2+ minutes, used for waiting time charges.
 *
 * @property string $id Primary key (UUID)
 * @property string $assignment_id FK to driver_assignments
 * @property \Carbon\Carbon $start_time When stationary period began
 * @property \Carbon\Carbon|null $end_time When movement resumed
 * @property float $start_latitude Latitude at stop start
 * @property float $start_longitude Longitude at stop start
 * @property float|null $end_latitude Latitude when resumed
 * @property float|null $end_longitude Longitude when resumed
 * @property int|null $duration_seconds Calculated duration
 */
class WaitingTimeRecord extends Model
{
    use HasUuids;

    protected $table = 'waiting_time_records';

    protected $fillable = [
        'assignment_id',
        'start_time',
        'end_time',
        'start_latitude',
        'start_longitude',
        'end_latitude',
        'end_longitude',
        'duration_seconds',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'start_latitude' => 'decimal:8',
        'start_longitude' => 'decimal:8',
        'end_latitude' => 'decimal:8',
        'end_longitude' => 'decimal:8',
        'duration_seconds' => 'integer',
    ];

    /**
     * Get the driver assignment this waiting record belongs to.
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DriverAssignment::class, 'assignment_id');
    }
}
