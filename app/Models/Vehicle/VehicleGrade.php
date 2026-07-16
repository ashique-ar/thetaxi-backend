<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Vehicle Grade Model
 * 
 * Represents different grades or quality levels for vehicles (e.g., Economy, Standard, Premium, Luxury).
 * Used to categorize vehicles based on their service level or quality tier.
 * 
 * @property string $id Primary key (UUID)
 * @property string|null $name Grade name (e.g., "Economy", "Premium")
 * @property string|null $description Detailed description of the grade
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection<Vehicle> $vehicles Vehicles with this grade
 */
class VehicleGrade extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_grades';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'description',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
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
     * Get all vehicles with this grade.
     */
    public function vehicles(): HasManyThrough
    {
        return $this->hasManyThrough(
            Vehicle::class,
            VehicleGroup::class,
            'grade_id',
            'vehicle_group_id'
        );
    }
}
