<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;

class CorporateTransportRouteMember extends BaseModel
{
    protected $table = 'corporate_transport_route_members';

    protected $fillable = [
        'route_id',
        'shift_id',
        'corporate_employee_id',
        'pickup_location_id',
        'dropoff_location_id',
        'route_order',
        'effective_from',
        'effective_to',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'route_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function route()
    {
        return $this->belongsTo(CorporateTransportRoute::class, 'route_id');
    }

    public function shift()
    {
        return $this->belongsTo(CorporateTransportShift::class, 'shift_id');
    }

    public function employee()
    {
        return $this->belongsTo(CorporateEmployee::class, 'corporate_employee_id');
    }

    public function pickupLocation()
    {
        return $this->belongsTo(CorporateEmployeeLocation::class, 'pickup_location_id');
    }

    public function dropoffLocation()
    {
        return $this->belongsTo(CorporateEmployeeLocation::class, 'dropoff_location_id');
    }
}
