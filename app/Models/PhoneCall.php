<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Booking\Booking;
use App\Models\Sales\SalesTask;
use App\Models\Sales\SalesOpportunity;
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
        'customer_id',
        'booking_id',
        'inquiry_id',
        'phone',
        'client_name',
        'summary',
        'call_time',
        'created_user_id',
        'updated_user_id',
        'external_provider',
        'external_call_key',
        'direction',
        'call_status',
        'agent_extension',
        'agent_email',
        'call_started_at',
        'call_ended_at',
        'talk_duration_seconds',
        'follow_up_task_id',
        'follow_up_due_at',
        'follow_up_notes',
        'follow_up_completed_at',
        'assigned_user_id',
        'company_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'call_time' => 'date',
        'call_started_at' => 'datetime',
        'call_ended_at' => 'datetime',
        'follow_up_due_at' => 'datetime',
        'follow_up_completed_at' => 'datetime',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function followUpTask(): BelongsTo
    {
        return $this->belongsTo(SalesTask::class, 'follow_up_task_id');
    }

    public function salesOpportunity(): HasOne
    {
        return $this->hasOne(SalesOpportunity::class, 'source_phone_call_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PhoneCallAssignment::class)->orderBy('event_at');
    }

    public function followUpAttempts(): HasMany
    {
        return $this->hasMany(PhoneCallFollowUpAttempt::class)->orderBy('attempted_at');
    }
}
