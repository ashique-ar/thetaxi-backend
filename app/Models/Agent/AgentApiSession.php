<?php

namespace App\Models\Agent;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent API session model.
 * 
 * @property string $id
 * @property string|null $agent_id
 * @property string|null $agent_api_id
 * @property \Carbon\Carbon|null $last_access
 * @property string|null $created_user_id
 * @property string|null $updated_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * 
 * @property-read Agent|null $agent
 * @property-read AgentApi|null $agentApi
 * @property-read User|null $createdBy
 * @property-read User|null $updatedBy
 */
class AgentApiSession extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'agent_id',
        'agent_api_id',
        'last_access',
        'method',
        'path',
        'status_code',
        'duration_ms',
        'ip_address',
        'user_agent',
        'metadata',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_access' => 'datetime',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the agent for this session.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /**
     * Get the agent API for this session.
     */
    public function agentApi(): BelongsTo
    {
        return $this->belongsTo(AgentApi::class, 'agent_api_id');
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
