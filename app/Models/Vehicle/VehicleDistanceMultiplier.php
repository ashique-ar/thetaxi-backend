<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vehicle Distance Multiplier Model
 * 
 * Represents distance-based pricing multipliers for vehicle rentals. Defines how rental
 * rates are adjusted based on the distance or mileage of the trip.
 * 
 * @property string $id Primary key (UUID)
 * @property int $min_km Minimum kilometers for this multiplier range
 * @property int $max_km Maximum kilometers for this multiplier range
 * @property float $multiplier Rate multiplier factor for this distance range
 * @property string|null $service_type_id Foreign key to service types table
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class VehicleDistanceMultiplier extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_distance_multipliers';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'min_km',
        'max_km',
        'multiplier',
        'service_type_id',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'min_km' => 'integer',
        'max_km' => 'integer',
        'multiplier' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user who created this record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Check if a given distance falls within this multiplier range.
     */
    public function appliesToDistance(int $kilometers): bool
    {
        return $kilometers >= $this->min_km && $kilometers <= $this->max_km;
    }
}
