<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Booking Status Model
 * 
 * Represents status change history for bookings. Tracks transitions between different
 * booking states (pending, confirmed, completed, cancelled, etc.) with timestamps.
 * 
 * @property string $id Primary key (UUID)
 * @property string|null $booking_id Foreign key to bookings table
 * @property string|null $old_status Previous booking status
 * @property string|null $new_status New booking status after change
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read Booking|null $booking Booking this status change belongs to
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class BookingStatus extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'booking_statuses';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'booking_id',
        'old_status',
        'new_status',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the booking this status change belongs to.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
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
}
