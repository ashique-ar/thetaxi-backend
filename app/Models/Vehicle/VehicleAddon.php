<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\Booking\BookingAddon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vehicle Addon Model
 * 
 * Represents additional services or features that can be added to vehicle bookings.
 * These are optional extras that customers can select (e.g., GPS, child seat, insurance).
 * 
 * @property string $id Primary key (UUID)
 * @property string|null $service_type_id Foreign key to service types table
 * @property string $name Addon name (e.g., "GPS Navigation", "Child Seat")
 * @property string|null $thumbnail Thumbnail image URL for the addon
 * @property string|null $min_qty Minimum quantity allowed
 * @property string|null $max_qty Maximum quantity allowed
 * @property string|null $description Detailed description of the addon
 * @property float $amount Addon price amount
 * @property string $rate_type Rate calculation type (flat or percentage)
 * @property \Carbon\Carbon|null $valid_from Date from which addon is valid
 * @property \Carbon\Carbon|null $valid_to Date until which addon is valid
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read ServiceType|null $serviceType Service type this addon belongs to
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection<BookingAddon> $bookingAddons Booking addon records using this addon
 */
class VehicleAddon extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_addons';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'service_type_id',
        'name',
        'thumbnail',
        'min_qty',
        'max_qty',
        'description',
        'amount',
        'rate_type',
        'billing_type',
        'valid_from',
        'valid_to',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the service type this addon belongs to.
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
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
     * Get all booking addon records using this addon.
     */
    public function bookingAddons(): HasMany
    {
        return $this->hasMany(BookingAddon::class, 'vehicle_addon_id');
    }
}
