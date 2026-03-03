<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehicleGroup;

class CorporateRateChart extends BaseModel
{
    protected $table = 'corporate_rate_charts';

    protected $logName = 'CorporateRateChart';

    protected $fillable = [
        'corporate_id',
        'name',
        'vehicle_group_id',
        'service_type_id',
        'per_km_rate',
        'per_hour_rate',
        'fixed_route_pricing',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'per_km_rate' => 'decimal:2',
        'per_hour_rate' => 'decimal:2',
        'fixed_route_pricing' => 'array',
    ];

    // Relationships

    public function corporate()
    {
        return $this->belongsTo(Corporate::class, 'corporate_id');
    }

    public function vehicleGroup()
    {
        return $this->belongsTo(VehicleGroup::class, 'vehicle_group_id');
    }

    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }
}
