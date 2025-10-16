<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Booking Channel Model
 * 
 * Represents different channels through which bookings can be made (website, mobile app,
 * phone call, agent booking, walk-in, etc.). Helps track booking sources for analytics.
 * 
 * @property string $id Primary key (UUID)
 * @property string|null $name Channel name (e.g., "Website", "Mobile App", "Phone")
 * @property string|null $description Detailed description of the booking channel
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read \Illuminate\Database\Eloquent\Collection<Booking> $bookings Bookings made through this channel
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class BookingChannel extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'booking_channels';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'description',
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
     * Get all bookings made through this channel.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'booking_channel_id');
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
