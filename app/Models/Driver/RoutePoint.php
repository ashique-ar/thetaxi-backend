<?php

namespace App\Models\Driver;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RoutePoint Model
 * 
 * Represents a GPS coordinate recorded during a driver session for route
 * replay and distance calculation. Does not use soft deletes for performance.
 * 
 * @property string $id Primary key (UUID)
 * @property string $session_id Foreign key to driver_sessions table
 * @property float $latitude GPS latitude
 * @property float $longitude GPS longitude
 * @property float|null $altitude Altitude in meters
 * @property float|null $speed Speed in km/h
 * @property float|null $heading Heading in degrees (0-360)
 * @property float|null $accuracy GPS accuracy in meters
 * @property \Carbon\Carbon $recorded_at Timestamp when point was recorded
 * @property \Carbon\Carbon $created_at Record creation timestamp
 * 
 * @property-read DriverSession $session Session this point belongs to
 */
class RoutePoint extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'route_points';

    /**
     * Indicates if the model should be timestamped.
     * Only created_at is used for performance.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'session_id',
        'latitude',
        'longitude',
        'altitude',
        'speed',
        'heading',
        'accuracy',
        'recorded_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'altitude' => 'decimal:2',
        'speed' => 'decimal:2',
        'heading' => 'decimal:2',
        'accuracy' => 'decimal:2',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Get the session this route point belongs to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(DriverSession::class, 'session_id');
    }
}
