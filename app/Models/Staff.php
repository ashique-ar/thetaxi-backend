<?php
// app/Models/Staff.php

namespace App\Models;

use App\Models\BaseModel;
use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrEmploymentSpell;
use App\Traits\UUID;

/**
 * App\Models\Staff
 *
 * @property string $id Primary key (UUID)
 * @property string $user_id Foreign key to users table
 * @property string $staff_type Staff type designation
 * @property string|null $code Staff unique code (optional)
 * @property \Illuminate\Support\Carbon|null $dob Date of birth (optional)
 * @property string|null $license_no Driving license number (optional)
 * @property \Illuminate\Support\Carbon|null $license_expiry License expiry date (optional)
 * @property string|null $address Staff address (optional)
 * @property string|null $country_id Foreign key to countries table (optional)
 * @property string|null $state_id Foreign key to states table (optional)
 * @property string|null $city Foreign key to cities table (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\User $user Staff user account
 * @property-read \App\Models\Country|null $country Staff's country
 * @property-read \App\Models\State|null $state Staff's state
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 */
class Staff extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'company_id',
        'staff_type',
        'collection_commission_enabled',
        'collection_commission_rate',
        'code',
        'nic',
        'dob',
        'license_no',
        'license_expiry',
        'address',
        'country_id',
        'state_id',
        'city',
        'employment_ended_at',
        'termination_reason',
        'terminated_by',
        'created_user_id',
        'updated_user_id'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'dob' => 'date',
        'license_expiry' => 'date',
        'collection_commission_enabled' => 'boolean',
        'collection_commission_rate' => 'decimal:2',
        'employment_ended_at' => 'datetime',
    ];

    // Relations

    /**
     * Get the user account for this staff member.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the staff's country.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the staff's state.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function state()
    {
        return $this->belongsTo(State::class);
    }


    /**
     * Get the user who created this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    public function paymentMethods()
    {
        return $this->morphMany(PaymentMethod::class, 'payable');
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function paymentMethodChanges()
    {
        return $this->hasMany(StaffPaymentMethodChange::class);
    }

    public function employmentSpells()
    {
        return $this->hasMany(HrEmploymentSpell::class);
    }

    public function employmentAssignments()
    {
        return $this->hasMany(HrEmploymentAssignment::class);
    }
}
