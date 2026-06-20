<?php

namespace App\Models\Driver;

use App\Models\BaseModel;
use App\Models\DrivingLicenseType;
use App\Traits\UUID;
use App\Models\User;
use App\Models\Country;
use App\Models\State;
use App\Models\Booking\Booking;
use App\Models\Vehicle\Vehicle;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Driver Model
 * 
 * Represents drivers who can operate vehicles for bookings. Contains personal information,
 * license details, and location data for drivers in the system.
 * 
 * @property string $id Primary key (UUID)
 * @property string $user_id Foreign key to users table
 * @property string|null $diplay_name Display name for the driver
 * @property string|null $code Unique driver code
 * @property string|null $nic National ID card number
 * @property string|null $license_no Driver's license number
 * @property \Carbon\Carbon|null $license_expiry License expiration date
 * @property string|null $license_type Type of driver's license
 * @property \Carbon\Carbon|null $dob Date of birth
 * @property string|null $address Physical address
 * @property string|null $country_id Foreign key to countries table
 * @property string|null $state_id Foreign key to states table
 * @property string|null $city Foreign key to cities table
 * @property string|null $remarks Additional remarks about the driver
 * @property bool $is_online Whether driver is currently online
 * @property \Carbon\Carbon|null $last_active_at Last activity timestamp
 * @property float|null $current_latitude Current GPS latitude
 * @property float|null $current_longitude Current GPS longitude
 * @property string|null $current_device_uuid Current device identifier
 * @property \Carbon\Carbon|null $company_id_renewal_date Company ID renewal date
 * @property \Carbon\Carbon|null $contract_expiry_date Contract expiration date
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read User $user User account associated with this driver
 * @property-read Country|null $country Country where driver is located
 * @property-read State|null $state State/province where driver is located
 * @property-read \Illuminate\Database\Eloquent\Collection<DriverLog> $logs Driver activity logs
 * @property-read \Illuminate\Database\Eloquent\Collection<Booking> $bookings Bookings assigned to this driver
 * @property-read \Illuminate\Database\Eloquent\Collection<DriverSession> $sessions Driver online sessions
 * @property-read DriverSession|null $activeSession Current active session
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class Driver extends BaseModel
{


    /**
     * The table associated with the model.
     */
    protected $table = 'drivers';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'code',
        'nic',
        'license_no',
        'license_expiry',
        'license_type',
        'dob',
        'address',
        'country_id',
        'state_id',
        'city',
        'remarks',
        'postal_code',
        'default_vehicle_id',
        'is_online',
        'last_active_at',
        'current_latitude',
        'current_longitude',
        'current_device_uuid',
        'created_user_id',
        'updated_user_id',
        'hire_date',
        'termination_date',
        'blood_group',
        'medical_conditions',
        'emergency_contact_name',
        'emergency_contact_phone',
        'availability_status',
        'working_schedule',
        'leave_schedule',
        'rest_windows',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'license_expiry' => 'date',
        'dob' => 'date',
        'is_online' => 'boolean',
        'last_active_at' => 'datetime',
        'current_latitude' => 'decimal:8',
        'current_longitude' => 'decimal:8',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user account associated with this driver.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the country where driver is located.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Get the state/province where driver is located.
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function licenseType(): BelongsTo
    {
        return $this->belongsTo(DrivingLicenseType::class, 'license_type');
    }

    /**
     * Get all driver activity logs.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(DriverLog::class, 'driver_id');
    }

    /**
     * Get all bookings assigned to this driver through booking items.
     */
    public function bookings(): HasManyThrough
    {
        return $this->hasManyThrough(
            Booking::class,
            \App\Models\Booking\BookingItem::class,
            'driver_id',  // Foreign key on booking_items table
            'id',         // Foreign key on bookings table
            'id',         // Local key on drivers table
            'booking_id'  // Local key on booking_items table
        );
    }

    /**
     * Get all booking items for this driver.
     */
    public function bookingItems(): HasMany
    {
        return $this->hasMany(\App\Models\Booking\BookingItem::class, 'driver_id');
    }

    /**
     * Get all driver online sessions.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(DriverSession::class, 'driver_id');
    }

    /**
     * Get all registered devices for this driver.
     */
    public function devices(): HasMany
    {
        return $this->hasMany(DriverDevice::class, 'driver_id');
    }

    /**
     * Get active devices for this driver.
     */
    public function activeDevices(): HasMany
    {
        return $this->hasMany(DriverDevice::class, 'driver_id')
            ->where('is_active', true);
    }

    public function paymentMethod(): MorphOne
    {
        return $this->morphOne(\App\Models\PaymentMethod::class, 'payable')->where('is_active', true);
    }

    /**
     * Get the current active session.
     */
    public function activeSession(): HasOne
    {
        return $this->hasOne(DriverSession::class, 'driver_id')
            ->where('status', 'active')
            ->latest('start_time');
    }

    /**
     * Get the user who created this record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function defaultVehicle()
    {
        return $this->belongsTo(Vehicle::class, 'default_vehicle_id');
    }

    /**
     * Get the user who last updated this record.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Get driver assignments
     */
    public function assignments()
    {
        return $this->hasMany(\App\Models\DriverAssignment::class);
    }

    /**
     * Get active assignments
     */
    public function activeAssignments()
    {
        return $this->assignments()->active();
    }

    /**
     * Get current assignment (active within current time)
     */
    public function currentAssignment()
    {
        return $this->assignments()
                    ->where('status', 'active')
                    ->where('assigned_from', '<=', now())
                    ->where('assigned_to', '>=', now())
                    ->with(['booking', 'booking.customer', 'booking.vehicle'])
                    ->first();
    }

    /**
     * Get current vehicle for this driver based on active assignment
     */
    public function getCurrentVehicle()
    {
        $currentAssignment = $this->currentAssignment();
        if ($currentAssignment && $currentAssignment->booking && $currentAssignment->booking->vehicle_id) {
            return Vehicle::find($currentAssignment->booking->vehicle_id);
        }
        
        return $this->defaultVehicle;
    }

    /**
     * Check if driver is available for given period
     */
    public function isAvailableForPeriod($from, $to, $excludeBookingId = null): bool
    {
        $conflictingAssignments = $this->assignments()
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where(function ($q) {
                $q->whereNull('trip_phase')
                    ->orWhereNotIn('trip_phase', ['completed', 'declined']);
            })
            ->whereHas('booking', function ($bq) {
                $bq->whereNotIn('status', ['cancelled', 'completed']);
            })
            ->when($excludeBookingId, function($q) use ($excludeBookingId) {
                $q->whereHas('booking', function($bq) use ($excludeBookingId) {
                    $bq->where('id', '!=', $excludeBookingId);
                });
            })
            ->where(function($q) use ($from, $to) {
                $q->whereBetween('assigned_from', [$from, $to])
                  ->orWhereBetween('assigned_to', [$from, $to])
                  ->orWhere(function($inner) use ($from, $to) {
                      $inner->where('assigned_from', '<=', $from)
                            ->where('assigned_to', '>=', $to);
                  });
            })
            ->get();

        return $conflictingAssignments->isEmpty();
    }

    /**
     * Get assignment conflicts for a given period
     */
    public function getAssignmentConflicts($from, $to, $excludeBookingId = null): array
    {
        $conflicts = $this->assignments()
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where(function ($q) {
                $q->whereNull('trip_phase')
                    ->orWhereNotIn('trip_phase', ['completed', 'declined']);
            })
            ->whereHas('booking', function ($bq) {
                $bq->whereNotIn('status', ['cancelled', 'completed']);
            })
            ->when($excludeBookingId, function($q) use ($excludeBookingId) {
                $q->whereHas('booking', function($bq) use ($excludeBookingId) {
                    $bq->where('id', '!=', $excludeBookingId);
                });
            })
            ->where(function($q) use ($from, $to) {
                $q->whereBetween('assigned_from', [$from, $to])
                  ->orWhereBetween('assigned_to', [$from, $to])
                  ->orWhere(function($inner) use ($from, $to) {
                      $inner->where('assigned_from', '<=', $from)
                            ->where('assigned_to', '>=', $to);
                  });
            })
            ->with(['booking', 'booking.customer', 'booking.vehicle'])
            ->get();

        return $conflicts->map(function($assignment) {
            return [
                'assignment_id' => $assignment->id,
                'booking_id' => $assignment->booking_id,
                'assignment_type' => $assignment->assignment_type,
                'overlap_type' => $assignment->overlap_type,
                'customer_name' => $assignment->booking->customer->name ?? 'Unknown',
                'vehicle_name' => $assignment->booking->vehicle ? 
                    $assignment->booking->vehicle->title 
                    : 'No Vehicle',
                'service_type' => $assignment->service_type,
                'from_datetime' => $assignment->assigned_from,
                'to_datetime' => $assignment->assigned_to,
                'status' => $assignment->status,
                'can_be_concurrent' => $assignment->assignment_type === 'concurrent',
                'requires_approval' => $assignment->requires_approval,
            ];
        })->toArray();
    }
}
