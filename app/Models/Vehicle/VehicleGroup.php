<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vehicle Group Model
 * 
 * Represents groups of vehicles within a grade. Groups vehicles with similar characteristics
 * or specifications together (e.g., "Land Cruiser ZX Series").
 * 
 * @property string $id Primary key (UUID)
 * @property string $grade_id Foreign key to vehicle grades table
 * @property string $make_id Foreign key to vehicle makes table
 * @property string $model_id Foreign key to vehicle models table
 * @property string $transmission_id Foreign key to vehicle transmissions table
 * @property string $fuel_type_id Foreign key to vehicle fuel types table
 * @property string $category_id Foreign key to vehicle categories table
 * @property string $class_id Foreign key to vehicle classes table
 * @property string $name Group name (e.g., "Land Cruiser ZX Series")
 * @property string|null $description Detailed description of the group
 * @property array|null $specs Group-level specifications as JSON
 * @property array|null $images Group images as JSON array
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read VehicleGrade $grade Vehicle grade this group belongs to
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection<Vehicle> $vehicles Vehicles in this group
 */
class VehicleGroup extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_groups';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'grade_id',
        'make_id',
        'model_id',
        'transmission_id',
        'fuel_type_id',
        'category_id',
        'class_id',
        'name',
        'description',
        'specs',
        'images',
        'thumbnail',
        'is_active',
        'is_featured',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'specs' => 'array',
        'images' => 'array',
        'thumbnail' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the vehicle grade this group belongs to.
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(VehicleGrade::class, 'grade_id');
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    public function transmission(): BelongsTo
    {
        return $this->belongsTo(VehicleTransmission::class, 'transmission_id');
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(VehicleFuelType::class, 'fuel_type_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }
    
    public function class(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class, 'class_id');
    }


    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'vehicle_group_id');
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

    /**
     * Get all vehicles in this group.
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'vehicle_group_id');
    }
}
