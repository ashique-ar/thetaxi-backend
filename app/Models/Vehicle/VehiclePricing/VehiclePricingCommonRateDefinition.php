<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Models\Service\ServiceType;
use App\Models\User;
use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pricing Common Rate Definition Model
 * 
 * Represents baseline pricing rates that can be applied across vehicle groups.
 * These rates serve as default values that can be overridden by specific vehicle group pricing.
 * 
 * @property string $id Primary key (UUID)
 * @property string $service_type_id Foreign key to service_types table
 * @property string $vehicle_group_id Foreign key to vehicle_groups table (optional for global rates)
 * @property string $code Unique code for the rate definition
 * @property string $name Name of the rate definition
 * @property string|null $description Description of the rate definition
 * @property string $common_rate_type Type of common rate (e.g., percentage, fixed amount)
 * @property bool $is_mandatory Whether this rate is mandatory for the service type
 * @property bool $is_active Whether this rate is currently active
 * @property int $sort_order Order in which this rate should be displayed
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 */
class VehiclePricingCommonRateDefinition extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_pricing_common_rate_definitions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'service_type_id',
        'vehicle_group_id',
        'code',
        'name',
        'description',
        'common_rate_type',
        'owner_type',
        'owner_id',
        'priority',
        'is_mandatory',
        'is_active',
        'sort_order',
        'created_user_id',
        'updated_user_id'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'value' => 'decimal:2',
        'is_mandatory' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'sort_order' => 'integer'
    ];

    /**
     * Get the service type this rate definition applies to
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    /**
     * Get the user who created this record
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Scope a query to only include active rate definitions
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include mandatory rate definitions
     */
    public function scopeMandatory($query)
    {
        return $query->where('is_mandatory', true);
    }

    /**
     * Scope a query for specific service type
     */
    public function scopeForServiceType($query, $serviceTypeId)
    {
        return $query->where('service_type_id', $serviceTypeId);
    }

    /**
     * Scope a query for global rates (no specific service type)
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('service_type_id');
    }    
}
