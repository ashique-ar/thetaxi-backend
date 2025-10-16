<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\Booking\BookingPricing
 *
 * @property string $id Primary key (UUID)
 * @property string $booking_id Foreign key to bookings table
 * @property string $slab_definition_id Foreign key to vehicle_pricing_slab_definitions table
 * @property string $vehicle_group_pricing_id Foreign key to vehicle_group_pricings table
 * @property float $calculated_amount Calculated pricing amount
 * @property string $rate_type Rate type (per_hour, per_day, flat_rate)
 * @property float $applied_rate Applied rate value
 * @property int $hours_calculated Hours used in calculation
 * @property int $days_calculated Days used in calculation
 * @property bool $minimum_charge_applied Whether minimum charge was applied
 * @property bool $includes_fuel Whether pricing includes fuel
 * @property bool $includes_driver Whether pricing includes driver
 * @property string|null $created_by User who created this record
 * @property string|null $updated_by User who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class BookingPricing extends BaseModel
{
    protected $table = 'booking_pricings';

    protected $fillable = [
        'booking_id',
        'slab_definition_id',
        'vehicle_group_pricing_id',
        'calculated_amount',
        'rate_type',
        'applied_rate',
        'hours_calculated',
        'days_calculated',
        'minimum_charge_applied',
        'includes_fuel',
        'includes_driver',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'calculated_amount' => 'decimal:2',
        'applied_rate' => 'decimal:2',
        'minimum_charge_applied' => 'boolean',
        'includes_fuel' => 'boolean',
        'includes_driver' => 'boolean',
        'hours_calculated' => 'integer',
        'days_calculated' => 'integer'
    ];

    /**
     * Get the booking that owns this pricing.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the slab definition used for this pricing.
     */
    public function slabDefinition(): BelongsTo
    {
        return $this->belongsTo(VehiclePricingSlabDefinition::class);
    }

    /**
     * Get the vehicle group pricing used for this booking.
     */
    public function vehicleGroupPricing(): BelongsTo
    {
        return $this->belongsTo(VehicleGroupPricing::class);
    }
}
