<?php

namespace App\Models\Service;

use App\Models\BaseModel;
use App\Models\Vehicle\VehicleGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Service Package Return Rule Model
 *
 * Defines pricing rules for return trips based on day offset from outbound trip.
 * Rules can be package-wide or specific to a vehicle group.
 *
 * @property string $id
 * @property string $service_package_id
 * @property string|null $vehicle_group_id
 * @property int $day_offset_min
 * @property int|null $day_offset_max
 * @property float $charge_percentage
 * @property string|null $label
 * @property string|null $description
 * @property bool $same_vehicle_required
 * @property bool $same_driver_required
 * @property int|null $min_wait_minutes
 * @property int|null $max_wait_hours
 * @property bool $is_active
 * @property int $priority
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 */
class ServicePackageReturnRule extends BaseModel
{
    protected $table = 'service_package_return_rules';

    protected $fillable = [
        'service_package_id',
        'vehicle_group_id',
        'day_offset_min',
        'day_offset_max',
        'charge_percentage',
        'label',
        'description',
        'same_vehicle_required',
        'same_driver_required',
        'min_wait_minutes',
        'max_wait_hours',
        'is_active',
        'priority',
        'effective_from',
        'effective_to',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'day_offset_min' => 'integer',
        'day_offset_max' => 'integer',
        'charge_percentage' => 'decimal:2',
        'same_vehicle_required' => 'boolean',
        'same_driver_required' => 'boolean',
        'min_wait_minutes' => 'integer',
        'max_wait_hours' => 'integer',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    /**
     * Get the service package this rule belongs to.
     */
    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class);
    }

    /**
     * Get the vehicle group this rule is specific to (optional).
     */
    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class);
    }

    /**
     * Scope to filter only active rules.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter rules effective on a given date.
     */
    public function scopeEffectiveOn(Builder $query, ?Carbon $date = null): Builder
    {
        $date = $date ?? Carbon::today();

        return $query->where(function ($q) use ($date) {
            $q->whereNull('effective_from')
                ->orWhere('effective_from', '<=', $date);
        })->where(function ($q) use ($date) {
            $q->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $date);
        });
    }

    /**
     * Scope to match rules by day offset.
     *
     * @param Builder $query
     * @param int $dayOffset Number of days between outbound and return trip
     */
    public function scopeMatchesDayOffset(Builder $query, int $dayOffset): Builder
    {
        return $query->where('day_offset_min', '<=', $dayOffset)
            ->where(function ($q) use ($dayOffset) {
                $q->whereNull('day_offset_max')
                    ->orWhere('day_offset_max', '>=', $dayOffset);
            });
    }

    /**
     * Scope to filter by vehicle group (or package-level rules if null).
     */
    public function scopeForVehicleGroup(Builder $query, ?string $vehicleGroupId = null): Builder
    {
        if ($vehicleGroupId) {
            // Include both vehicle-specific and package-level rules
            return $query->where(function ($q) use ($vehicleGroupId) {
                $q->where('vehicle_group_id', $vehicleGroupId)
                    ->orWhereNull('vehicle_group_id');
            });
        }

        // Only package-level rules
        return $query->whereNull('vehicle_group_id');
    }

    /**
     * Find the best matching return rule for given parameters.
     *
     * @param string $servicePackageId
     * @param int $dayOffset Days between outbound and return
     * @param string|null $vehicleGroupId Optional vehicle group for specific rules
     * @param Carbon|null $effectiveDate Date to check effectiveness (default: today)
     * @return static|null
     */
    public static function findMatchingRule(
        string $servicePackageId,
        int $dayOffset,
        ?string $vehicleGroupId = null,
        ?Carbon $effectiveDate = null
    ): ?self {
        return static::query()
            ->where('service_package_id', $servicePackageId)
            ->active()
            ->effectiveOn($effectiveDate)
            ->matchesDayOffset($dayOffset)
            ->forVehicleGroup($vehicleGroupId)
            ->orderByRaw('vehicle_group_id IS NULL ASC') // Vehicle-specific rules first
            ->orderByDesc('priority')
            ->first();
    }

    /**
     * Calculate the return trip charge based on one-way fare.
     *
     * @param float $oneWayFare The fare for the outbound trip
     * @return float The calculated return trip fare
     */
    public function calculateReturnFare(float $oneWayFare): float
    {
        return round($oneWayFare * ($this->charge_percentage / 100), 2);
    }

    /**
     * Get all rules for a service package, ordered by day offset.
     *
     * @param string $servicePackageId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getRulesForPackage(string $servicePackageId)
    {
        return static::query()
            ->where('service_package_id', $servicePackageId)
            ->active()
            ->orderBy('day_offset_min')
            ->orderByDesc('priority')
            ->get();
    }

    /**
     * Check if the wait time constraint is satisfied.
     *
     * @param Carbon $outboundDropoffTime
     * @param Carbon $returnPickupTime
     * @return bool
     */
    public function isWaitTimeValid(Carbon $outboundDropoffTime, Carbon $returnPickupTime): bool
    {
        $waitMinutes = $outboundDropoffTime->diffInMinutes($returnPickupTime);

        // Check minimum wait time
        if ($this->min_wait_minutes !== null && $waitMinutes < $this->min_wait_minutes) {
            return false;
        }

        // Check maximum wait time
        if ($this->max_wait_hours !== null) {
            $maxWaitMinutes = $this->max_wait_hours * 60;
            if ($waitMinutes > $maxWaitMinutes) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the discount percentage (100 - charge_percentage).
     *
     * @return float
     */
    public function getDiscountPercentageAttribute(): float
    {
        return 100 - $this->charge_percentage;
    }

    /**
     * Get a human-readable description of the day offset range.
     *
     * @return string
     */
    public function getDayRangeDescriptionAttribute(): string
    {
        if ($this->day_offset_min === 0 && $this->day_offset_max === 0) {
            return 'Same day';
        }

        if ($this->day_offset_min === 1 && $this->day_offset_max === 1) {
            return 'Next day';
        }

        if ($this->day_offset_max === null) {
            return "{$this->day_offset_min}+ days";
        }

        if ($this->day_offset_min === $this->day_offset_max) {
            return "{$this->day_offset_min} days";
        }

        return "{$this->day_offset_min}-{$this->day_offset_max} days";
    }
}
