<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehicleGroup;

class Corporate extends BaseModel
{
    protected $table = 'corporates';

    protected $logName = 'Corporate';

    protected $fillable = [
        'name',
        'contact_email',
        'contact_phone',
        'billing_address',
        'is_active',
        'approval_required',
        'exempt_coordinator_from_approval',
        'coordinator_can_view_payments',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'approval_required' => 'boolean',
        'exempt_coordinator_from_approval' => 'boolean',
        'coordinator_can_view_payments' => 'boolean',
    ];

    // Relationships

    public function departments()
    {
        return $this->hasMany(CorporateDepartment::class, 'corporate_id');
    }

    public function vehicleGroups()
    {
        return $this->belongsToMany(VehicleGroup::class, 'corporate_vehicle_groups', 'corporate_id', 'vehicle_group_id')
            ->withInactive();
    }

    public function serviceTypes()
    {
        return $this->belongsToMany(ServiceType::class, 'corporate_service_types', 'corporate_id', 'service_type_id')
            ->wherePivot('is_active', true)
            ->withPivot(['is_active'])
            ->withTimestamps();
    }

    public function allServiceTypes()
    {
        return $this->belongsToMany(ServiceType::class, 'corporate_service_types', 'corporate_id', 'service_type_id')
            ->withPivot(['is_active'])
            ->withTimestamps();
    }

    public function employees()
    {
        return $this->hasMany(CorporateEmployee::class, 'corporate_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'corporate_account_id');
    }

    public function transportPrograms()
    {
        return $this->hasMany(CorporateTransportProgram::class, 'corporate_id');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
