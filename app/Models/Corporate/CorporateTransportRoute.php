<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehicleGroup;

class CorporateTransportRoute extends BaseModel
{
    protected $table = 'corporate_transport_routes';

    protected $fillable = [
        'program_id',
        'name',
        'direction',
        'service_type_id',
        'vehicle_group_id',
        'origin_location',
        'destination_location',
        'capacity',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'origin_location' => 'array',
        'destination_location' => 'array',
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function program()
    {
        return $this->belongsTo(CorporateTransportProgram::class, 'program_id');
    }

    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    public function vehicleGroup()
    {
        return $this->belongsTo(VehicleGroup::class, 'vehicle_group_id');
    }

    public function members()
    {
        return $this->hasMany(CorporateTransportRouteMember::class, 'route_id');
    }
}
