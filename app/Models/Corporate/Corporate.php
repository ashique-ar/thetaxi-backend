<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehicleGroup;

class Corporate extends BaseModel
{
    protected $attributes = [
        'booking_notification_emails' => '["info@thetaxi.lk"]',
    ];

    protected $table = 'corporates';

    protected $logName = 'Corporate';

    protected $fillable = [
        'name',
        'contact_email',
        'booking_notification_emails',
        'contact_phone',
        'billing_address',
        'is_active',
        'approval_required',
        'approval_mode',
        'approval_role_ids',
        'approval_employee_ids',
        'exempt_coordinator_from_approval',
        'coordinator_can_view_payments',
        'booking_vehicle_display',
        'default_payment_arrangement',
        'booking_number_prefix',
        'booking_number_start',
        'booking_number_increment',
        'booking_number_digits',
        'booking_item_number_prefix',
        'booking_item_number_start',
        'booking_item_number_increment',
        'booking_item_number_digits',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'approval_required' => 'boolean',
        'approval_role_ids' => 'array',
        'approval_employee_ids' => 'array',
        'exempt_coordinator_from_approval' => 'boolean',
        'coordinator_can_view_payments' => 'boolean',
        'booking_vehicle_display' => 'string',
        'booking_notification_emails' => 'array',
        'booking_number_start' => 'integer',
        'booking_number_increment' => 'integer',
        'booking_number_digits' => 'integer',
        'booking_item_number_start' => 'integer',
        'booking_item_number_increment' => 'integer',
        'booking_item_number_digits' => 'integer',
        'reference_numbers_seeded_at' => 'datetime',
    ];

    protected $hidden = [
        'reference_numbers_seeded_at',
    ];

    // Relationships

    public function departments()
    {
        return $this->hasMany(CorporateDepartment::class, 'corporate_id');
    }

    public function vehicleGroups()
    {
        return $this->belongsToMany(VehicleGroup::class, 'corporate_vehicle_groups', 'corporate_id', 'vehicle_group_id');
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

    public function distancePricingPolicies()
    {
        return $this->hasMany(CorporateDistancePricingPolicy::class, 'corporate_id');
    }

    public function billingTerms()
    {
        return $this->hasMany(CorporateBillingTerm::class, 'corporate_id');
    }

    public function serviceDistancePolicies()
    {
        return $this->hasMany(CorporateServiceDistancePolicy::class, 'corporate_id');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
