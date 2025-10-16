<?php
namespace App\Models\Booking;

use App\Models\BaseModel;

/**
 * App\Models\Booking\BookingAddon
 *
 * @property string $id Primary key (UUID)
 * @property string $booking_id Foreign key to bookings table
 * @property string $addon_id Foreign key to vehicle_addons table
 * @property int $qty Quantity (default: 1)
 * @property float|null $rate Rate per unit (optional)
 * @property float|null $amount Total amount (optional)
 * @property bool $is_insurance Whether this is an insurance addon (default: false)
 * @property bool $is_milage Whether this is a mileage addon (default: false)
 * @property string|null $label Addon label (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\Booking\Booking $booking Booking this addon belongs to
 * @property-read \App\Models\Vehicle\VehicleAddon $addon Vehicle addon details
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 */
class BookingAddon extends BaseModel
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'booking_id',
        'addon_id',
        'qty',
        'rate',
        'amount',
        'is_insurance',
        'is_milage',
        'label',
        'created_user_id',
        'updated_user_id'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'qty' => 'integer',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'is_insurance' => 'boolean',
        'is_milage' => 'boolean',
    ];

    // Relations

    /**
     * Get the booking this addon belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the vehicle addon details.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function addon()
    {
        return $this->belongsTo(\App\Models\Vehicle\VehicleAddon::class, 'addon_id');
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
}
