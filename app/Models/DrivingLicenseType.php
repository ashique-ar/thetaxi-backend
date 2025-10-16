<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\UUID;

/**
 * Driving License Type Model
 * 
 * Represents different categories or classes of driving licenses (motorcycle, car, truck, etc.).
 * Defines the types of vehicles that can be operated with each license category.
 * 
 * @property string $id Primary key (UUID)
 * @property string|null $name License type name (e.g., "Class A", "Motorcycle", "Heavy Vehicle")
 * @property string|null $description Detailed description of what vehicles this license covers
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read \Illuminate\Database\Eloquent\Collection<DrivingLicense> $licenses Driving licenses of this type
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class DrivingLicenseType extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'driving_license_types';

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
     * Get all driving licenses of this type.
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(DrivingLicense::class, 'license_type');
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
