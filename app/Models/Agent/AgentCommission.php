<?php

namespace App\Models\Agent;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\Booking\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent commission model.
 * 
 * @property string $id
 * @property string $agent_id
 * @property string $booking_id
 * @property float $amount
 * @property bool $paid
 * @property \Carbon\Carbon|null $paid_at
 * @property string|null $created_user_id
 * @property string|null $updated_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * 
 * @property-read Agent $agent
 * @property-read Booking $booking
 * @property-read User|null $createdBy
 * @property-read User|null $updatedBy
 */
class AgentCommission extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'agent_id',
        'booking_id',
        'amount',
        'paid',
        'paid_at',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'paid' => 'boolean',
        'paid_at' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the agent for this commission.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Agent\Agent::class, 'agent_id');
    }

    /**
     * Get the booking for this commission.
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
}
