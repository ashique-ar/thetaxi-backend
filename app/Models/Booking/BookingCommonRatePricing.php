<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\Booking\BookingCommonRatePricing
 *
 * @property string $id Primary key (UUID)
 * @property string $booking_id Foreign key to bookings table
 * @property string $common_rate_definition_id Foreign key to vehicle_pricing_common_rate_definitions table
 * @property string|null $vehicle_group_common_rate_pricing_id Foreign key to vehicle_group_common_rate_pricings table
 * @property float $calculated_amount Calculated common rate amount
 * @property string $rate_type Rate type (percentage, per_hour, per_day, per_km, fixed_amount)
 * @property float $applied_rate Applied rate value
 * @property float|null $calculation_base Base value used for calculation (e.g., base amount for percentage)
 * @property bool $is_mandatory Whether this common rate is mandatory
 * @property string|null $created_by User who created this record
 * @property string|null $updated_by User who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class BookingCommonRatePricing extends BaseModel
{
    

    protected $table = 'booking_common_rate_pricings';

    protected $fillable = [
        'booking_id',
        'common_rate_definition_id',
        'vehicle_group_common_rate_pricing_id',
        'calculated_amount',
        'rate_type',
        'applied_rate',
        'calculation_base',
        'is_mandatory',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'calculated_amount' => 'decimal:2',
        'applied_rate' => 'decimal:2',
        'calculation_base' => 'decimal:2',
        'is_mandatory' => 'boolean'
    ];

    /**
     * Get the booking that owns this common rate pricing.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the common rate definition used for this pricing.
     */
    public function commonRateDefinition(): BelongsTo
    {
        return $this->belongsTo(VehiclePricingCommonRateDefinition::class);
    }

    /**
     * Get the vehicle group common rate pricing (if custom rate is used).
     */
    public function vehicleGroupCommonRatePricing(): BelongsTo
    {
        return $this->belongsTo(VehicleGroupCommonRatePricing::class);
    }
}
