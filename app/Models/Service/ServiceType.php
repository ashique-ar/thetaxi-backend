<?php
namespace App\Models\Service;

use App\Models\BaseModel;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Traits\UUID;

/**
 * App\Models\Service\ServiceType
 *
 * @property string $id Primary key (UUID)
 * @property string $code Service type unique code
 * @property string $name Service type name
 * @property string|null $description Service type description (optional)
 * @property string|null $slug Service type URL slug (optional)
 * @property string|null $thumbnail Service type thumbnail image (optional)
 * @property int|null $priority Service type priority (optional)
 * @property bool|null $is_internal Whether service is internal only (optional)
 * @property string|null $terms Service terms and conditions (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition[] $pricingSlabs Service pricing slabs
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Booking\Booking[] $bookings Bookings for this service type
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Analytics\DemandForecast[] $demandForecasts Demand forecasts
 */
class ServiceType extends BaseModel
{


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'type', // self_drive or with_driver
        'slug',
        'thumbnail',
        'pricing_mode',
        'uses_dropoff_time',
        'allow_return_trip',
        'frontend_category',
        'priority',
        'minimum_km',
        'is_internal',
        'terms',
        'created_user_id',
        'updated_user_id'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_internal' => 'boolean',
        'priority' => 'integer',
        'minimum_km' => 'decimal:2',
        'uses_dropoff_time' => 'boolean',
        'allow_return_trip' => 'boolean',
        'pricing_mode' => 'string',
        'frontend_category' => 'string',
    ];

    // Relations

    /**
     * Get the user who created this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Get all pricing slabs for this service type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function pricingSlabs()
    {
        return $this->hasMany(VehiclePricingSlabDefinition::class);
    }

    public function packages()
    {
        return $this->hasMany(ServicePackage::class);
    }

    /**
     * Get all bookings for this service type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookings()
    {
        return $this->hasMany(\App\Models\Booking\Booking::class);
    }

    public function addons()
    {
        return $this->hasMany(\App\Models\Vehicle\VehicleAddon::class);
    }

    /**
     * Get all demand forecasts for this service type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function demandForecasts()
    {
        return $this->hasMany(\App\Models\Analytics\DemandForecast::class);
    }

    /**
     * Scope a query to only include active service types.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }
}
