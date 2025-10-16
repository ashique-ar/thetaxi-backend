<?php
// app/Models/State.php

namespace App\Models;

use App\Models\BaseModel;
use App\Traits\UUID;

/**
 * App\Models\State
 *
 * @property string $id Primary key (UUID)
 * @property string|null $country_id Foreign key to countries table (optional)
 * @property string|null $name State name (optional)
 * @property string|null $description State description (optional)
 * @property string|null $url State URL (optional)
 * @property string|null $lng Longitude coordinate (optional)
 * @property string|null $lat Latitude coordinate (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\Country|null $country State's country
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 */
class State extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'country_id',
        'name',
        'description',
        'url',
        'lng',
        'lat',
        'created_user_id',
        'updated_user_id'
    ];

    // Relations

    /**
     * Get the country this state belongs to.
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
