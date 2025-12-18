<?php

namespace App\Models;

use App\Models\BaseModel;
use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * API session metadata for personal access tokens
 *
 * @property string $id
 * @property string $token_id
 * @property string $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device
 * @property string|null $browser
 * @property string|null $os
 * @property string|null $location
 * @property \Carbon\Carbon|null $last_active
 */
class ApiSession extends BaseModel
{
    use UUID;

    protected $table = 'api_sessions';

    protected $fillable = [
        'token_id',
        'user_id',
        'name',
        'ip_address',
        'user_agent',
        'device',
        'browser',
        'os',
        'location',
        'last_active',
        'current'
    ];

    protected $casts = [
        'last_active' => 'datetime',
        'current' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
