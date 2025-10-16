<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\Booking\BookingVariableCustomization
 *
 * @property string $id Primary key (UUID)
 * @property string|null $booking_id Foreign key to bookings table (optional for draft customizations)
 * @property string $session_id Session ID for non-persisted customizations
 * @property string $variable_name Name of the customized variable
 * @property string $variable_type Type of variable (slab_rate, common_rate, fixed_value, etc.)
 * @property float $original_value Original calculated value
 * @property float $custom_value Custom value set by user
 * @property string|null $customization_reason Reason for customization
 * @property string $context Context where customization was applied (base_pricing, addon_pricing)
 * @property array|null $metadata Additional metadata for the customization
 * @property string|null $created_user_id ID of user who created this customization
 * @property string|null $updated_user_id ID of user who last updated this customization
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class BookingVariableCustomization extends BaseModel
{
    use UUID;

    protected $fillable = [
        'booking_id',
        'session_id',
        'vehicle_group_id',
        'variable_name',
        'variable_type',
        'original_value',
        'custom_value',
        'customization_reason',
        'context',
        'metadata',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'original_value' => 'float',
        'custom_value' => 'float',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the booking associated with this customization
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the user who created this customization
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this customization
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_user_id');
    }

    /**
     * Check if the customization represents a significant change
     */
    public function isSignificantChange(): bool
    {
        $changePercentage = abs($this->custom_value - $this->original_value) / $this->original_value * 100;
        return $changePercentage > 10; // 10% threshold
    }

    /**
     * Get customization impact description
     */
    public function getImpactDescription(): string
    {
        $difference = $this->custom_value - $this->original_value;
        $changeType = $difference > 0 ? 'increase' : 'decrease';
        $percentage = abs($difference / $this->original_value * 100);
        
        return sprintf(
            '%s %s by %.1f%% (LKR %.2f)',
            ucfirst($this->variable_name),
            $changeType,
            $percentage,
            abs($difference)
        );
    }
}
