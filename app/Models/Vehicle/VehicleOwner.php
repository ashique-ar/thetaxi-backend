<?php
namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Models\Driver\Driver;
use App\Traits\UUID;

/**
 * App\Models\Vehicle\VehicleOwner
 *
 * @property string $id Primary key (UUID)
 * @property string $owner_type_id Foreign key to vehicle_owner_types table
 * @property string $name Owner name
 * @property string|null $email Owner email (optional)
 * @property string|null $address Owner address (optional)
 * @property string|null $country_id Foreign key to countries table (optional)
 * @property string|null $state_id Foreign key to states table (optional)
 * @property string|null $city Foreign key to cities table (optional)
 * @property array|null $contact_info Contact information (JSON, optional)
 * @property string|null $company_id Foreign key to companies table (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\Vehicle\VehicleOwnerType $type Owner type
 * @property-read \App\Models\Company|null $company Owner's company
 * @property-read \App\Models\Country|null $country Owner's country
 * @property-read \App\Models\State|null $state Owner's state
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Vehicle\Vehicle[] $vehicles Vehicles owned by this owner
 */
class VehicleOwner extends BaseModel
{
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'driver_id',
        'owner_type_id',
        'address',
        'country_id',
        'state_id',
        'postal_code',
        'dob',
        'city',
        'license_number',
        'license_expiry',
        'notes',
        'created_user_id',
        'updated_user_id'

    ];
    
    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'contact_info' => 'array',
    ];

    // Relations

    /**
     * Get the owner type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function type()
    {
        return $this->belongsTo(VehicleOwnerType::class, 'owner_type_id');
    }

    /**
     * Get the owner's company.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(\App\Models\Company\Company::class, 'company_id');
    }

    /**
     * Get the owner's country.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function country()
    {
        return $this->belongsTo(\App\Models\Country::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }
    
    /**
     * Get the owner's state.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function state()
    {
        return $this->belongsTo(\App\Models\State::class);
    }

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
     * Get all vehicles owned by this owner.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'owner_id');
    }

    public function paymentMethods()
    {
        return $this->morphMany(\App\Models\PaymentMethod::class, 'payable');
    }
}
