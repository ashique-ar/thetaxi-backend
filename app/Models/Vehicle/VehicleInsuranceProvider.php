<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vehicle Insurance Provider Model
 * 
 * Represents insurance companies that provide vehicle insurance coverage.
 * Contains provider information and contact details for insurance management.
 * 
 * @property string $id Primary key (UUID)
 * @property string $name Insurance provider company name
 * @property string|null $contact Contact information for the provider
 * @property string|null $description Additional information about the provider
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read \Illuminate\Database\Eloquent\Collection<VehicleInsurance> $insurances Vehicle insurance policies from this provider
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class VehicleInsuranceProvider extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_insurance_providers';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'contact',
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
     * Get all vehicle insurance policies from this provider.
     */
    public function insurances(): HasMany
    {
        return $this->hasMany(VehicleInsurance::class, 'provider_id');
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
