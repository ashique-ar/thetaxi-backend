<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Company;
use App\Models\Document;
use App\Models\Driver\Driver;
use App\Traits\UUID;

/**
 * App\Models\Vehicle\Vehicle
 *
 * @property string $id Primary key (UUID)
 * @property string|null $class_id Foreign key to vehicle_classes table (optional)
 * @property string|null $contract_type_id Foreign key to vehicle_contract_types table (optional)
 * @property string|null $category_id Foreign key to vehicle_categories table (optional)
 * @property string|null $model_id Foreign key to vehicle_models table (optional)
 * @property string|null $make_id Foreign key to vehicle_makes table (optional)
 * @property string|null $owner_id Foreign key to vehicle_owners table (optional)
 * @property string|null $grade_id Foreign key to vehicle_grades table (optional)
 * @property string|null $vehicle_group_id Foreign key to vehicle_groups table (optional)
 * @property string|null $title Vehicle title
 * @property string|null $registration_no Vehicle registration number
 * @property string|null $chasis_no Vehicle chassis number
 * @property string|null $engine_no Vehicle engine number
 * @property string|null $license_plate Vehicle license plate
 * @property int|null $model_year Vehicle model year
 * @property string|null $color Vehicle color
 * @property string|null $ac Air conditioning availability
 * @property string|null $thumbnail Vehicle thumbnail image
 * @property string|null $slug Vehicle URL slug
 * @property string|null $bags Number of bags/luggage capacity
 * @property string|null $seats Number of seats
 * @property string|null $refundable_deposit Refundable deposit amount
 * @property string|null $year Vehicle year
 * @property string|null $tagline Vehicle tagline
 * @property string|null $description Vehicle description
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\Vehicle\VehicleClass|null $vclass Vehicle class
 * @property-read \App\Models\Vehicle\VehicleFuelType|null $fuelType Vehicle fuel type
 * @property-read \App\Models\Vehicle\VehicleTransmission|null $transmission Vehicle transmission
 * @property-read \App\Models\Vehicle\VehicleContractType|null $contractType Vehicle contract type
 * @property-read \App\Models\Vehicle\VehicleCategory|null $category Vehicle category
 * @property-read \App\Models\Vehicle\VehicleModel|null $model Vehicle model
 * @property-read \App\Models\Vehicle\VehicleMake|null $make Vehicle make
 * @property-read \App\Models\Vehicle\VehicleOwner|null $owner Vehicle owner
 * @property-read \App\Models\Vehicle\VehicleGrade|null $grade Vehicle grade
 * @property-read \App\Models\Vehicle\VehicleGroup|null $group Vehicle group
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Vehicle\VehicleInsurance[] $insurances Vehicle insurances
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Vehicle\VehicleMaintenanceSchedule[] $schedules Maintenance schedules
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Vehicle\VehicleMaintenanceRecord[] $records Maintenance records
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Booking\Booking[] $bookings Vehicle bookings
 */
class Vehicle extends BaseModel
{


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'contract_type_id',
        'owner_id',
        'vehicle_group_id',
        'company_id',
        'ownership_type',
        'usage_type',
        'payment_model',
        'owner_payment_method_id',
        'assignment_policy',
        'agreement_start_date',
        'agreement_end_date',
        'agreement_status',
        'initial_mileage',
        'current_mileage',
        'handover_mileage',
        'handover_at',
        'handover_location',
        'handover_notes',
        'monthly_payment_commitment',
        'monthly_mileage_limit',
        'excess_mileage_rate',
        'title',
        'registration_no',
        'chasis_no',
        'engine_no',
        'license_plate',
        'model_year',
        'color',
        'ac',
        'thumbnail',
        'actual_vehicle_images',
        'slug',
        'bags',
        'seats',
        'refundable_deposit',
        'year',
        'tagline',
        'status',
        'availability_status',
        'description',

        
        'default_driver_id',
        'force_default_driver',
        'allow_concurrent_assignments',


        'created_user_id',
        'updated_user_id',

        //new fields
        'self_driven_compatible',
    ];

    protected $casts = [
        'thumbnail' => 'array',
        'actual_vehicle_images' => 'array',
        'ac' => 'boolean',
        'is_active' => 'boolean',
        'agreement_start_date' => 'date',
        'agreement_end_date' => 'date',
        'handover_at' => 'datetime',
        'initial_mileage' => 'integer',
        'current_mileage' => 'integer',
        'handover_mileage' => 'integer',
        'monthly_payment_commitment' => 'decimal:2',
        'monthly_mileage_limit' => 'decimal:2',
        'excess_mileage_rate' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
    /**
     * Get the vehicle's contract type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function contractType()
    {
        return $this->belongsTo(VehicleContractType::class, 'contract_type_id');
    }

    /**
     * Get the vehicle's owner.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function owner()
    {
        return $this->belongsTo(VehicleOwner::class, 'owner_id');
    }

    public function ownerPaymentMethod()
    {
        return $this->belongsTo(\App\Models\PaymentMethod::class, 'owner_payment_method_id');
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function leases()
    {
        return $this->hasMany(VehicleLease::class)->orderByDesc('start_date');
    }

    public function ownershipHistory()
    {
        return $this->hasMany(VehicleOwnershipHistory::class)->orderByDesc('effective_at');
    }

    /**
     * Get the vehicle's grade.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function grade()
    {
        return $this->belongsTo(VehicleGrade::class, 'grade_id');
    }

    /**
     * Get the vehicle's group.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function group()
    {
        return $this->belongsTo(VehicleGroup::class, 'vehicle_group_id');
    }

    /**
     * Get the vehicle's group.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vehicleGroup()
    {
        return $this->belongsTo(VehicleGroup::class, 'vehicle_group_id');
    }

    /**
     * Get the user who created this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updatedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_user_id');
    }

    /**
     * Get all insurances for this vehicle.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function insurances()
    {
        return $this->hasMany(VehicleInsurance::class);
    }

    public function revenueLicenses()
    {
        return $this->hasMany(VehicleRevenueLicense::class);
    }

    public function activeInsurance()
    {
        return $this->hasOne(VehicleInsurance::class)
            ->where('status', 'active')
            ->latest('end_date');
    }

    public function activeRevenueLicense()
    {
        return $this->hasOne(VehicleRevenueLicense::class)
            ->where('status', 'active')
            ->latest('expiry_date');
    }

    /**
     * Get all maintenance schedules for this vehicle.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function schedules()
    {
        return $this->hasMany(VehicleMaintenanceSchedule::class);
    }

    /**
     * Get all maintenance records for this vehicle.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function records()
    {
        return $this->hasMany(VehicleMaintenanceRecord::class);
    }

    /**
     * Get all bookings for this vehicle through booking items.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function bookings()
    {
        return $this->hasManyThrough(
            Booking::class,
            \App\Models\Booking\BookingItem::class,
            'vehicle_id', // Foreign key on booking_items table
            'id',         // Foreign key on bookings table
            'id',         // Local key on vehicles table
            'booking_id'  // Local key on booking_items table
        );
    }


    /**
     * Get all bookings for this vehicle.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookingItems()
    {
        return $this->hasMany(BookingItem::class);
    }

    public function maintenanceRecords()
    {
        return $this->hasMany(VehicleMaintenanceRecord::class);
    }

    /**
     * Get the company that owns this vehicle.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function defaultDriver()
    {
        return $this->belongsTo(Driver::class, 'default_driver_id');
    }

    public function commissions()
    {
        return $this->hasMany(VehicleCommission::class, 'vehicle_id');
    }

    public function activeCommission()
    {
        return $this->hasOne(VehicleCommission::class, 'vehicle_id')
            ->where('is_active', true)
            ->where('effective_from', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now()->toDateString());
            })
            ->latest('effective_from');
    }

    /**
     * Get all companies associated with this vehicle (many-to-many).
     * This can be used if a vehicle can belong to multiple companies.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function companies()
    {
        return $this->belongsToMany(Company::class, 'vehicle_companies', 'vehicle_id', 'company_id');
    }

    /**
     * Get vehicle assignments
     */
    public function assignments()
    {
        return $this->hasMany(VehicleAssignment::class);
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
            ->with(['booking', 'booking.customer', 'booking.driver'])
            ->first();
    }

    /**
     * Get current driver for this vehicle based on active assignment
     */
    public function getCurrentDriver()
    {
        $currentAssignment = $this->currentAssignment();
        if ($currentAssignment && $currentAssignment->booking && $currentAssignment->booking->driver_id) {
            return Driver::find($currentAssignment->booking->driver_id);
        }

        return $this->defaultDriver;
    }

    /**
     * Check if vehicle allows concurrent assignments
     */
    public function allowsConcurrentAssignments(): bool
    {
        return (bool) $this->allow_concurrent_assignments;
    }

    /**
     * Check if vehicle has forced default driver
     */
    public function hasForceDefaultDriver(): bool
    {
        return (bool) $this->force_default_driver && $this->default_driver_id;
    }

    /**
     * Check if vehicle is available for given period (considering concurrent assignments)
     */
    public function isAvailableForPeriod($from, $to, $excludeBookingId = null): bool
    {
        $conflictingAssignments = $this->assignments()
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereHas('booking', function ($bq) {
                $bq->whereNotIn('status', ['cancelled', 'completed']);
            })
            ->when($excludeBookingId, function ($q) use ($excludeBookingId) {
                $q->whereHas('booking', function ($bq) use ($excludeBookingId) {
                    $bq->where('id', '!=', $excludeBookingId);
                });
            })
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('assigned_from', [$from, $to])
                    ->orWhereBetween('assigned_to', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to) {
                        $inner->where('assigned_from', '<=', $from)
                            ->where('assigned_to', '>=', $to);
                    });
            })
            ->get();

        if ($conflictingAssignments->isEmpty()) {
            return true;
        }

        // If concurrent assignments are allowed and all conflicts are concurrent-compatible
        if ($this->allowsConcurrentAssignments()) {
            return $conflictingAssignments->every(function ($assignment) {
                return $assignment->assignment_type === 'concurrent' ||
                    $assignment->overlap_type === 'partial';
            });
        }

        return false;
    }

    /**
     * Get assignment conflicts for a given period
     */
    public function getAssignmentConflicts($from, $to, $excludeBookingId = null): array
    {
        $conflicts = $this->assignments()
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereHas('booking', function ($bq) {
                $bq->whereNotIn('status', ['cancelled', 'completed']);
            })
            ->when($excludeBookingId, function ($q) use ($excludeBookingId) {
                $q->whereHas('booking', function ($bq) use ($excludeBookingId) {
                    $bq->where('id', '!=', $excludeBookingId);
                });
            })
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('assigned_from', [$from, $to])
                    ->orWhereBetween('assigned_to', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to) {
                        $inner->where('assigned_from', '<=', $from)
                            ->where('assigned_to', '>=', $to);
                    });
            })
            ->with(['booking', 'booking.customer', 'booking.driver'])
            ->get();

        return $conflicts->map(function ($assignment) {
            return [
                'assignment_id' => $assignment->id,
                'booking_id' => $assignment->booking_id,
                'assignment_type' => $assignment->assignment_type,
                'overlap_type' => $assignment->overlap_type,
                'customer_name' => $assignment->booking->customer?->user?->first_name . ' ' . $assignment->booking?->customer?->user?->last_name ?? 'Unknown',
                'driver_name' => $assignment->booking->driver?->user?->first_name . ' ' . $assignment->booking?->driver?->user?->last_name ?? 'No Driver',
                'service_type' => $assignment->service_type,
                'from_datetime' => $assignment->assigned_from,
                'to_datetime' => $assignment->assigned_to,
                'status' => $assignment->status,
                'can_be_concurrent' => $assignment->assignment_type === 'concurrent' || $this->allowsConcurrentAssignments(),
                'requires_approval' => $assignment->requires_approval,
            ];
        })->toArray();
    }
}
