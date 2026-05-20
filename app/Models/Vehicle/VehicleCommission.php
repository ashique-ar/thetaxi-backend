<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;

class VehicleCommission extends BaseModel
{
    protected $fillable = [
        'vehicle_id',
        'commission_type',
        'rate',
        'amount',
        'effective_from',
        'effective_to',
        'notes',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'amount' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
