<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\UUID;

/**
 * Phone Call Model
 * 
 * Represents phone calls logged in the system. Used for tracking customer inquiries,
 * support calls, and other telephone communications.
 * 
 * @property string $id Primary key (UUID)
 * @property string|null $phone Phone number that called
 * @property string|null $client_name Name of the client who called
 * @property string|null $summary Summary of the phone call conversation
 * @property \Carbon\Carbon|null $call_time Date and time of the call
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class PhoneCall extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'phone_calls';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'phone',
        'client_name',
        'summary',
        'call_time',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'call_time' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

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
