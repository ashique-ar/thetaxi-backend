<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vehicle Maintenance Record Model
 * 
 * Represents completed maintenance activities on vehicles. Records actual maintenance
 * performed, including dates, costs, and detailed notes about the work done.
 * 
 * @property string $id Primary key (UUID)
 * @property string $vehicle_id Foreign key to vehicles table
 * @property string $schedule_id Foreign key to vehicle maintenance schedules table
 * @property \Carbon\Carbon $performed_date Date when maintenance was performed
 * @property float $cost Cost of the maintenance work
 * @property string|null $notes Detailed notes about the maintenance performed
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read Vehicle $vehicle Vehicle that received maintenance
 * @property-read VehicleMaintenanceSchedule $schedule Maintenance schedule this record fulfills
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class VehicleMaintenanceRecord extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_maintenance_records';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'vehicle_id',
        'schedule_id',
        'performed_date',
        'cost',
        'status',
        'notes',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'performed_date' => 'date',
        'cost' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the vehicle that received maintenance.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get the maintenance schedule this record fulfills.
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(VehicleMaintenanceSchedule::class, 'schedule_id');
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
