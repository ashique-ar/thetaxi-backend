<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;

class CorporateEmployeeLocation extends BaseModel
{
    protected $table = 'corporate_employee_locations';

    protected $fillable = [
        'corporate_employee_id',
        'label',
        'address',
        'latitude',
        'longitude',
        'city',
        'country',
        'place_id',
        'is_default_pickup',
        'is_default_dropoff',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_default_pickup' => 'boolean',
        'is_default_dropoff' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(CorporateEmployee::class, 'corporate_employee_id');
    }
}
