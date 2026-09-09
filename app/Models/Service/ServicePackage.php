<?php

namespace App\Models\Service;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class ServicePackage extends BaseModel
{
    protected $table = 'service_packages';

    protected $fillable = [
        'service_type_id',
        'name',
        'code',
        'description',
        'max_km_per_day',
        'max_km_per_package',
        'price_multiplier',
        'rate_type',
        'default_duration_hours',
        'default_duration_minutes',
        'is_active',
        'supports_return_trip',
        'sort_order',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'max_km_per_day' => 'decimal:2',
        'max_km_per_package' => 'decimal:2',
        'price_multiplier' => 'decimal:4',
        'default_duration_hours' => 'integer',
        'default_duration_minutes' => 'integer',
        'is_active' => 'boolean',
        'supports_return_trip' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function districtAdjustments(): HasMany
    {
        return $this->hasMany(DistrictPricingAdjustment::class, 'service_package_id');
    }

    /**
     * Get return trip pricing rules for this package.
     */
    public function returnRules(): HasMany
    {
        return $this->hasMany(ServicePackageReturnRule::class, 'service_package_id');
    }

    /**
     * Get active return rules ordered by priority.
     */
    public function activeReturnRules(): HasMany
    {
        return $this->returnRules()
            ->where('is_active', true)
            ->orderBy('day_offset_min')
            ->orderByDesc('priority');
    }

    /**
     * Find the applicable return trip rule for a given day offset.
     *
     * @param int $dayOffset Days between outbound and return trip
     * @param string|null $vehicleGroupId Optional vehicle group for specific rules
     * @param Carbon|null $effectiveDate Date to check effectiveness
     * @param float|null $kilometers Total kilometers for the trip
     * @return ServicePackageReturnRule|null
     */
    public function findReturnRule(
        int $dayOffset, 
        ?string $vehicleGroupId = null, 
        ?Carbon $effectiveDate = null,
        ?float $kilometers = null
    ): ?ServicePackageReturnRule {
        return ServicePackageReturnRule::findMatchingRule(
            $this->id,
            $dayOffset,
            $vehicleGroupId,
            $effectiveDate,
            $kilometers
        );
    }

    /**
     * Check if this package supports return trips.
     *
     * @return bool
     */
    public function supportsReturnTrip(): bool
    {
        return $this->returnRules()->active()->exists();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
