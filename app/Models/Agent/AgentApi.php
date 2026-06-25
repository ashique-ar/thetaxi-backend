<?php

namespace App\Models\Agent;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agent API configuration model.
 * 
 * @property string $id
 * @property string|null $title
 * @property string|null $description
 * @property string $api_key
 * @property string|null $created_user_id
 * @property string|null $updated_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * 
 * @property-read User|null $createdBy
 * @property-read User|null $updatedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AgentApiSession> $sessions
 */
class AgentApi extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'api_key',
        'agent_id',
        'rate_limit',
        'access_level',
        'allowed_ips',
        'status',
        'total_requests',
        'last_used_at',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'rate_limit' => 'integer',
        'total_requests' => 'integer',
        'last_used_at' => 'datetime',
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

    /**
     * Get the API sessions for this agent API.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(AgentApiSession::class, 'agent_api_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
