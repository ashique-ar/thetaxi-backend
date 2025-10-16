<?php
// app/Models/Country.php

namespace App\Models;

use App\Models\BaseModel;
use App\Traits\UUID;

/**
 * App\Models\Country
 *
 * @property string $id Primary key (UUID)
 * @property string $name Country name
 * @property string|null $code Country code (2-5 chars, optional)
 * @property string|null $code3 Country code (3 chars, optional)
 * @property string|null $callcode Country calling code (optional)
 * @property string|null $googlelode Google location data (optional)
 * @property string|null $description Country description (optional)
 * @property string|null $url Country URL (optional)
 * @property string|null $tagline Country tagline (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\State[] $states States in this country
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Company\Company[] $companies Companies in this country
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Customer[] $customers Customers in this country
 */
class Country extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'code3',
        'callcode',
        'googlelode',
        'description',
        'url',
        'tagline',
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
     * Get all states in this country.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function states()
    {
        return $this->hasMany(State::class);
    }

    /**
     * Get all companies in this country.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function companies()
    {
        return $this->hasMany(\App\Models\Company\Company::class);
    }

    /**
     * Get all customers in this country.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
}
