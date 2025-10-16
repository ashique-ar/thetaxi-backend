<?php
// app/Models/Customer.php

namespace App\Models;

use App\Models\BaseModel;
use App\Traits\UUID;

/**
 * App\Models\Customer
 *
 * @property string $id Primary key (UUID)
 * @property string $user_id Foreign key to users table
 * @property string|null $code Customer unique code (optional)
 * @property string|null $client_type Client type (optional)
 * @property string|null $license_no Driving license number (optional)
 * @property \Illuminate\Support\Carbon|null $license_expiry License expiry date (optional)
 * @property string|null $license_type License type (optional)
 * @property \Illuminate\Support\Carbon|null $dob Date of birth (optional)
 * @property string|null $address Customer address (optional)
 * @property string|null $country_id Foreign key to countries table (optional)
 * @property string|null $state_id Foreign key to states table (optional)
 * @property string|null $city Foreign key to cities table (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\User $user Customer's user account
 * @property-read \App\Models\Country|null $country Customer's country
 * @property-read \App\Models\State|null $state Customer's state
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Booking\Booking[] $bookings Customer's bookings
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\BillingAddress[] $billingAddresses Customer's billing addresses
 */
class Customer extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'code',
        'type',
        'sub_type',
        'category',
        'passport_number',
        'code',
        'nic',
        'wedding_date',
        'license_no',
        'license_expiry',
        'license_type',
        'dob',
        'address',
        'postal_code',
        'gender',
        'country_id',
        'state_id',
        'city',
        'created_user_id',
        'updated_user_id'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'license_expiry' => 'date',
        'dob' => 'date',
    ];

    // Relations

    /**
     * Get the user account for this customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the customer's country.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Get the customer's state.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function state()
    {
        return $this->belongsTo(State::class);
    }


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
     * Get all bookings for this customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookings()
    {
        return $this->hasMany(\App\Models\Booking\Booking::class, 'customer_id');
    }

    /**
     * Get all billing addresses for this customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function billingAddresses()
    {
        return $this->hasMany(BillingAddress::class);
    }
}
