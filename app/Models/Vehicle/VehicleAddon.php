<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\Service\ServiceType;
use App\Models\User;
use App\Models\Booking\BookingAddon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vehicle Addon Model
 * 
 * Represents additional services or features that can be added to vehicle bookings.
 * These are optional extras that customers can select (e.g., GPS, child seat, insurance).
 * 
 * @property string $id Primary key (UUID)
 * @property string|null $service_type_id Foreign key to service types table
 * @property string|null $category_id Category for grouping addons
 * @property string $name Addon name (e.g., "GPS Navigation", "Child Seat")
 * @property string $addon_type Type: service, item, insurance, fee, discount
 * @property string $pricing_type Pricing calculation type
 * @property string $quantity_unit Unit of quantity: pieces, km, hours, days, passengers
 * @property string|null $thumbnail Thumbnail image URL for the addon
 * @property int|null $min_qty Minimum quantity allowed
 * @property int|null $max_qty Maximum quantity allowed
 * @property bool $allow_quantity_selection Whether customer can select quantity
 * @property int|null $threshold_quantity Quantity threshold for alternate pricing
 * @property float|null $threshold_price Price per unit after threshold
 * @property string|null $description Detailed description of the addon
 * @property float $amount Addon price amount
 * @property string $rate_type Rate calculation type (flat or percentage)
 * @property string $billing_type Billing frequency (per_package, per_day, per_hour)
 * @property bool $is_taxable Whether addon is taxable
 * @property float $tax_rate Tax rate percentage
 * @property bool $is_active Whether addon is currently active
 * @property bool $is_mandatory Whether addon is mandatory for bookings
 * @property bool $is_optional Whether addon is optional
 * @property string $availability_type Type of availability: always, conditional, seasonal, service_specific
 * @property array|null $availability_conditions JSON conditions for availability
 * @property array|null $compatible_vehicle_types Vehicle types this addon is compatible with
 * @property int $sort_order Display sort order
 * @property string|null $icon Icon identifier for the addon
 * @property array|null $tags Tags for categorization
 * @property string|null $internal_notes Internal notes for staff
 * @property array|null $integration_settings Integration-specific settings
 * @property \Carbon\Carbon|null $valid_from Date from which addon is valid
 * @property \Carbon\Carbon|null $valid_to Date until which addon is valid
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read ServiceType|null $serviceType Service type this addon belongs to
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection<BookingAddon> $bookingAddons Booking addon records using this addon
 */
class VehicleAddon extends BaseModel
{


    /**
     * The table associated with the model.
     */
    protected $table = 'vehicle_addons';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'service_type_id',
        'category_id',
        'name',
        'addon_type',
        'pricing_type',
        'quantity_unit',
        'thumbnail',
        'min_qty',
        'max_qty',
        'allow_quantity_selection',
        'threshold_quantity',
        'threshold_price',
        'description',
        'amount',
        'rate_type',
        'billing_type',
        'is_taxable',
        'tax_rate',
        'is_active',
        'is_mandatory',
        'is_optional',
        'availability_type',
        'availability_conditions',
        'compatible_vehicle_types',
        'sort_order',
        'icon',
        'tags',
        'internal_notes',
        'integration_settings',
        'valid_from',
        'valid_to',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'threshold_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'min_qty' => 'integer',
        'max_qty' => 'integer',
        'threshold_quantity' => 'integer',
        'sort_order' => 'integer',
        'allow_quantity_selection' => 'boolean',
        'is_taxable' => 'boolean',
        'is_active' => 'boolean',
        'is_mandatory' => 'boolean',
        'is_optional' => 'boolean',
        'availability_conditions' => 'array',
        'compatible_vehicle_types' => 'array',
        'tags' => 'array',
        'integration_settings' => 'array',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'addon_type' => 'item',
        'pricing_type' => 'fixed',
        'quantity_unit' => 'pieces',
        'rate_type' => 'flat',
        'billing_type' => 'per_day',
        'is_active' => true,
        'is_mandatory' => false,
        'is_optional' => true,
        'is_taxable' => false,
        'tax_rate' => 0,
        'allow_quantity_selection' => true,
        'availability_type' => 'always',
        'sort_order' => 0,
    ];

    /**
     * Get the service type this addon belongs to.
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(VehicleAddonCategory::class, 'category_id');
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

    /**
     * Get all booking addon records using this addon.
     */
    public function bookingAddons(): HasMany
    {
        return $this->hasMany(BookingAddon::class, 'vehicle_addon_id');
    }

    /**
     * Scope to filter by active status.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by service type.
     */
    public function scopeForServiceType($query, $serviceTypeId)
    {
        return $query->where(function ($q) use ($serviceTypeId) {
            $q->where('service_type_id', $serviceTypeId)
                ->orWhereNull('service_type_id'); // Universal addons
        });
    }

    /**
     * Scope to filter by addon type.
     */
    public function scopeOfType($query, $addonType)
    {
        return $query->where('addon_type', $addonType);
    }

    /**
     * Scope to filter by availability.
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', now());
            });
    }

    /**
     * Check if addon is currently valid.
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();

        if ($this->valid_from && $this->valid_from > $now) {
            return false;
        }

        if ($this->valid_to && $this->valid_to < $now) {
            return false;
        }

        return true;
    }

    /**
     * Calculate price for given quantity and context.
     */
    public function calculatePrice(int $quantity, array $context = []): float
    {
        $baseAmount = (float) $this->amount;
        $total = 0;

        // Handle threshold-based pricing
        if ($this->threshold_quantity && $this->threshold_price && $quantity > $this->threshold_quantity) {
            $baseQty = $this->threshold_quantity;
            $thresholdQty = $quantity - $this->threshold_quantity;
            $total = ($baseAmount * $baseQty) + ((float) $this->threshold_price * $thresholdQty);
        } else {
            $total = $baseAmount * $quantity;
        }

        // Apply tax if applicable
        if ($this->is_taxable && $this->tax_rate > 0) {
            $total += $total * ($this->tax_rate / 100);
        }

        return round($total, 2);
    }
}
