<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vehicle Image Model
 * 
 * Represents images associated with vehicles. Stores vehicle photos for display
 * in listings, galleries, and vehicle detail pages.
 * 
 * @property string $id Primary key (UUID)
 * @property string $vehicle_id Foreign key to vehicles table
 * @property string $image_url URL or path to the vehicle image
 * @property string|null $caption Image caption or description
 * @property int|null $sort_order Display order for image sorting
 * @property bool|null $is_primary Whether this is the primary/main image
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read Vehicle $vehicle Vehicle this image belongs to
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class VehicleImage extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_images';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'vehicle_id',
        'image_url',
        'caption',
        'sort_order',
        'is_primary',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'sort_order' => 'integer',
        'is_primary' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the vehicle this image belongs to.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
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
