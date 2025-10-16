<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vehicle Insurance Model
 * 
 * Represents insurance policies for vehicles. Tracks insurance coverage details
 * including provider, policy type, dates, and premium amounts.
 * 
 * @property string $id Primary key (UUID)
 * @property string $vehicle_id Foreign key to vehicles table
 * @property string $provider_id Foreign key to vehicle insurance providers table
 * @property string $insurance_type_id Foreign key to vehicle insurance types table
 * @property string $policy_number Insurance policy number
 * @property \Carbon\Carbon $start_date Insurance coverage start date
 * @property \Carbon\Carbon $end_date Insurance coverage end date
 * @property float $premium_amount Insurance premium amount
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read Vehicle $vehicle Vehicle this insurance covers
 * @property-read VehicleInsuranceProvider $provider Insurance provider
 * @property-read VehicleInsuranceType $insuranceType Type of insurance coverage
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class VehicleInsurance extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_insurances';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'vehicle_id',
        'provider_id',
        'insurance_type_id',
        'policy_number',
        'start_date',
        'end_date',
        'premium_amount',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'premium_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the vehicle this insurance covers.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get the insurance provider.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(VehicleInsuranceProvider::class, 'provider_id');
    }

    /**
     * Get the type of insurance coverage.
     */
    public function insuranceType(): BelongsTo
    {
        return $this->belongsTo(VehicleInsuranceType::class, 'insurance_type_id');
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
