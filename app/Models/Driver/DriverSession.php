<?php

namespace App\Models\Driver;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DriverSession Model
 * 
 * Represents a driver's online session period, tracking when they go online/offline,
 * their start/end locations, and total distance traveled during the session.
 * 
 * @property string $id Primary key (UUID)
 * @property string $driver_id Foreign key to drivers table
 * @property string $device_uuid Device identifier from mobile app
 * @property string $status Session status (active, completed, auto_closed)
 * @property \Carbon\Carbon $start_time Session start timestamp
 * @property \Carbon\Carbon|null $end_time Session end timestamp
 * @property float|null $start_latitude Starting GPS latitude
 * @property float|null $start_longitude Starting GPS longitude
 * @property float|null $end_latitude Ending GPS latitude
 * @property float|null $end_longitude Ending GPS longitude
 * @property float|null $total_distance_km Total distance traveled in km
 * @property string|null $assignment_id Optional link to assignment
 * @property array|null $metadata Additional session metadata
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read Driver $driver Driver who owns this session
 * @property-read \Illuminate\Database\Eloquent\Collection<RoutePoint> $routePoints GPS points recorded during session
 */
class DriverSession extends BaseModel
{
    /**
     * The table associated with the model.
     */
    protected $table = 'driver_sessions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'driver_id',
        'device_uuid',
        'status',
        'start_time',
        'end_time',
        'start_latitude',
        'start_longitude',
        'end_latitude',
        'end_longitude',
        'total_distance_km',
        'assignment_id',
        'metadata',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'start_latitude' => 'decimal:8',
        'start_longitude' => 'decimal:8',
        'end_latitude' => 'decimal:8',
        'end_longitude' => 'decimal:8',
        'total_distance_km' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the driver who owns this session.
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /**
     * Get all route points recorded during this session.
     */
    public function routePoints(): HasMany
    {
        return $this->hasMany(RoutePoint::class, 'session_id');
    }

    /**
     * Scope to filter active sessions.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to filter completed sessions.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to filter auto-closed sessions.
     */
    public function scopeAutoClosed($query)
    {
        return $query->where('status', 'auto_closed');
    }
}
