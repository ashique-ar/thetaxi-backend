<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DistrictPricingAdjustment extends BaseModel
{
    protected $table = 'district_pricing_adjustments';

    protected $fillable = [
        'district_id',
        'service_type_id',
        'service_package_id',
        'vehicle_group_id',
        'percentage_change',
        'is_available',
        'request_quote',
        'priority',
        'effective_from',
        'effective_to',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'percentage_change' => 'decimal:4',
        'is_available' => 'boolean',
        'request_quote' => 'boolean',
        'priority' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeEffective(Builder $query, ?Carbon $date = null): Builder
    {
        $date = $date ?? now();

        return $query->where(function (Builder $q) use ($date) {
            $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
        })->where(function (Builder $q) use ($date) {
            $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
        });
    }

    /**
     * Resolve the best adjustment for a given district/service/package/group combo.
     */
    public static function resolveAdjustment(
        ?string $districtId,
        ?string $serviceTypeId,
        ?string $packageId = null,
        ?string $vehicleGroupId = null,
        ?Carbon $date = null
    ): array {
        if (!$districtId || !$serviceTypeId) {
            return [
                'percentage' => 0,
                'is_available' => true,
                'request_quote' => false,
                'id' => null,
            ];
        }

        $query = static::active()
            ->effective($date)
            ->where('district_id', $districtId)
            ->where('service_type_id', $serviceTypeId);

        if ($packageId) {
            $query->where(function (Builder $q) use ($packageId) {
                $q->whereNull('service_package_id')
                    ->orWhere('service_package_id', $packageId);
            });
        }

        if ($vehicleGroupId) {
            $query->where(function (Builder $q) use ($vehicleGroupId) {
                $q->whereNull('vehicle_group_id')
                    ->orWhere('vehicle_group_id', $vehicleGroupId);
            });
        }

        $adjustment = $query->orderBy('priority', 'desc')
            ->orderBy('percentage_change', 'desc')
            ->first();

        return [
            'percentage' => $adjustment?->percentage_change ?? 0,
            'is_available' => $adjustment?->is_available ?? true,
            'request_quote' => $adjustment?->request_quote ?? false,
            'id' => $adjustment?->id,
        ];
    }
}
