<?php
// app/Models/Company/Company.php

namespace App\Models;

use App\Models\BaseModel;
use App\Traits\UUID;

/**
 * App\Models\Company\Company
 *
 * @property string $id Primary key (UUID)
 * @property string $name Company name
 * @property string|null $address Company address (optional)
 * @property string|null $region_id Foreign key to company_regions table (optional)
 * @property string|null $country_id Foreign key to countries table (optional)
 * @property string|null $state_id Foreign key to company_districts table (optional)
 * @property string|null $city Foreign key to company_cities table (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\Region|null $region Company's region
 * @property-read \App\Models\Country|null $country Company's country
 * @property-read \App\Models\State|null $state Company's district
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Vehicle\Vehicle[] $vehicles Company's vehicles
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users Company's users
 */
class Company extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'address',
        'latitude',
        'longitude',
        'region_id',
        'country_id',
        'state_id',
        'is_default',
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
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    // Relations

    /**
     * Get the company's region.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Get the company's country.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function country()
    {
        return $this->belongsTo(\App\Models\Country::class);
    }

    /**
     * Get the company's district.
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
     * Get all vehicles belonging to this company.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function vehicles()
    {
        return $this->hasMany(\App\Models\Vehicle\Vehicle::class);
    }

    /**
     * Get all users belonging to this company.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function users()
    {
        return $this->hasMany(\App\Models\User::class);
    }

    /**
     * Get default company location (first company with coordinates)
     * This is used for calculating delivery/pickup distances
     *
     * @return self|null
     */
    public static function getDefaultCompany()
    {
        return static::where('is_default', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->first();
    }

    /**
     * Get formatted location string
     *
     * @return string
     */
    public function getLocationStringAttribute(): string
    {
        if ($this->latitude && $this->longitude) {
            return "{$this->latitude}, {$this->longitude}";
        }
        return $this->address ?? 'No location set';
    }
}
