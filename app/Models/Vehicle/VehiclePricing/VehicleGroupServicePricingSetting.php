<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehicleGroup;
use App\Traits\UUID;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleGroupServicePricingSetting extends BaseModel
{
    use UUID, SoftDeletes;

    protected $table = 'vehicle_group_service_pricing_settings';

    protected $fillable = [
        'vehicle_group_id',
        'service_type_id',
        'is_inquiry_only',
        'is_hidden',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'is_inquiry_only' => 'boolean',
        'is_hidden' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class, 'vehicle_group_id');
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    public function scopeForVehicleGroup(Builder $query, string $vehicleGroupId): Builder
    {
        return $query->where('vehicle_group_id', $vehicleGroupId);
    }

    public function scopeForServiceType(Builder $query, string $serviceTypeId): Builder
    {
        return $query->where('service_type_id', $serviceTypeId);
    }
}
