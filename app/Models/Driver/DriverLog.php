<?php

namespace App\Models\Driver;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use App\Models\Booking\Booking;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Driver Log Model
 * 
 * Represents daily activity logs submitted by drivers. Tracks work hours, mileage,
 * and booking completion details for payroll and performance monitoring.
 * 
 * @property string $id Primary key (UUID)
 * @property string|null $driver_id Foreign key to drivers table
 * @property string|null $booking_id Foreign key to bookings table
 * @property \Carbon\Carbon|null $log_code Log reference code
 * @property \Carbon\Carbon|null $log_date Date of the logged activity
 * @property string|null $start_time Work start time
 * @property string|null $end_time Work end time
 * @property int|null $start_km Starting odometer reading
 * @property int|null $end_km Ending odometer reading
 * @property string|null $start_image Photo at work start
 * @property string|null $end_image Photo at work end
 * @property string $status Log approval status (pending, approved, rejected)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read Driver|null $driver Driver who submitted this log
 * @property-read Booking|null $booking Booking associated with this log
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class DriverLog extends BaseModel
{
    //create log model with migration and let them print logbook for particular driver
    
    

    /**
     * The table associated with the model.
     */
    protected $table = 'driver_logs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'driver_id',
        'booking_id',
        'log_code',
        'log_date',
        'start_time',
        'end_time',
        'start_km',
        'end_km',
        'total_km',
        'start_image',
        'end_image',
        'particulars',
        'entry_source',
        'attachments',
        'status',
        'assigned_by',
        'assigned_at',
        'submitted_by',
        'submitted_at',
        'correction_notes',
        'revision_number',
        'verification_notes',
        'verified_by',
        'verified_at',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'log_code' => 'date',
        'log_date' => 'date',
        'start_km' => 'integer',
        'end_km' => 'integer',
        'total_km' => 'integer',
        'attachments' => 'array',
        'assigned_at' => 'datetime',
        'submitted_at' => 'datetime',
        'revision_number' => 'integer',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the driver who submitted this log.
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /**
     * Get the booking associated with this log.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /**
     * Get the user who created this record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
