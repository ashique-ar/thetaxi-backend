<?php
namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Traits\UUID;

/**
 * App\Models\Vehicle\VehicleFuelType
 *
 * @property string $id Primary key (UUID)
 * @property string|null $name Fuel type name (optional)
 * @property string|null $description Fuel type description (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Vehicle\Vehicle[] $vehicles Vehicles with this fuel type
 */
class VehicleFuelType extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'created_user_id',
        'updated_user_id'
    ];

    // Relations

    /**
     * Get the user who created this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updatedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_user_id');
    }

    /**
     * Get all vehicles with this fuel type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function vehicles()
    {
        return $this->hasManyThrough(
            Vehicle::class,
            VehicleGroup::class,
            'fuel_type_id',
            'vehicle_group_id'
        );
    }
}
