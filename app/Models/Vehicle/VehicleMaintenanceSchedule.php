<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vehicle Maintenance Schedule Model
 * 
 * Represents scheduled maintenance tasks for vehicles. Defines maintenance intervals
 * based on kilometers driven or time periods, helping track when maintenance is due.
 * 
 * @property string $id Primary key (UUID)
 * @property string $vehicle_id Foreign key to vehicles table
 * @property string $type Type of maintenance (oil_change, inspection, etc.)
 * @property int|null $interval_km Maintenance interval in kilometers
 * @property int|null $interval_days Maintenance interval in days
 * @property \Carbon\Carbon|null $next_due_date Next scheduled maintenance date
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read Vehicle $vehicle Vehicle this schedule belongs to
 * @property-read \Illuminate\Database\Eloquent\Collection<VehicleMaintenanceRecord> $records Maintenance records for this schedule
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class VehicleMaintenanceSchedule extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_maintenance_schedules';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'vehicle_id',
        'type',
        'interval_km',
        'interval_days',
        'next_due_date',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'interval_km' => 'integer',
        'interval_days' => 'integer',
        'next_due_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the vehicle this schedule belongs to.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get all maintenance records for this schedule.
     */
    public function records(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceRecord::class, 'schedule_id');
    }

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
}
