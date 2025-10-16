<?php

namespace App\Models\Agent;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use App\Models\Booking\Booking;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agent Model
 * 
 * Represents business agents or partners who can create bookings and earn commissions.
 * Agents have their own branding configuration and commission structures.
 * 
 * @property string $id Primary key (UUID)
 * @property string $user_id Foreign key to users table
 * @property string|null $code Unique agent code identifier
 * @property float $commission_rate Agent commission rate percentage
 * @property array|null $branding_config Agent branding configuration as JSON
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read User $user User account associated with this agent
 * @property-read \Illuminate\Database\Eloquent\Collection<Booking> $bookings Bookings created by this agent
 * @property-read \Illuminate\Database\Eloquent\Collection<AgentCommission> $commissions Commission records for this agent
 * @property-read \Illuminate\Database\Eloquent\Collection<AgentApi> $apis API configurations for this agent
 * @property-read \Illuminate\Database\Eloquent\Collection<AgentApiSession> $apiSessions API sessions for this agent
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class Agent extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'agents';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'code',
        'commission_rate',
        'branding_config',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'commission_rate' => 'decimal:2',
        'branding_config' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user account associated with this agent.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all bookings created by this agent.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'agent_id');
    }

    /**
     * Get all commission records for this agent.
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(AgentCommission::class, 'agent_id');
    }

    /**
     * Get all API configurations for this agent.
     */
    public function apis(): HasMany
    {
        return $this->hasMany(AgentApi::class, 'agent_id');
    }

    /**
     * Get all API sessions for this agent.
     */
    public function apiSessions(): HasMany
    {
        return $this->hasMany(AgentApiSession::class, 'agent_id');
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
