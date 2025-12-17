<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Models\Vehicle\VehicleGroup;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class ServicePackageRate extends BaseModel
{
    protected $table = 'service_package_rates';

    protected $fillable = [
        'service_package_id',
        'vehicle_group_id',
        'base_rate',
        'rate_type',
        'extra_km_rate',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'base_rate' => 'decimal:2',
        'extra_km_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class, 'vehicle_group_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
