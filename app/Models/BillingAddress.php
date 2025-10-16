<?php

namespace App\Models;

use App\Models\BaseModel;
use App\Traits\UUID;

/**
 * App\Models\BillingAddress
 *
 * @property string $id Primary key (UUID)
 * @property string|null $customer_id Foreign key to customers table (optional)
 * @property string|null $address_line_1 Address line 1 (optional)
 * @property string|null $address_line_2 Address line 2 (optional)
 * @property string|null $city Foreign key to cities table (optional)
 * @property string|null $state_id Foreign key to states table (optional)
 * @property string|null $country_id Foreign key to countries table (optional)
 * @property string|null $postal_code Postal/ZIP code (optional)
 * @property bool $is_default Whether this is the default billing address
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\Customer|null $customer Customer who owns this address
 * @property-read \App\Models\State|null $state Billing address state
 * @property-read \App\Models\Country|null $country Billing address country
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 */
class BillingAddress extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'address_line_1',
        'address_line_2',
        'city',
        'state_id',
        'country_id',
        'postal_code',
        'is_default',
        'created_user_id',
        'updated_user_id'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_default' => 'boolean',
    ];

    // Relations

    /**
     * Get the customer who owns this billing address.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }


    /**
     * Get the state for this billing address.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function state()
    {
        return $this->belongsTo(State::class);
    }

    /**
     * Get the country for this billing address.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function country()
    {
        return $this->belongsTo(Country::class);
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
}
