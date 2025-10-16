<?php

namespace App\Models\Session;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Search saved model.
 * 
 * @property string $id
 * @property string $session_id
 * @property array $query_data
 * @property array|null $results_data
 * @property \Carbon\Carbon $saved_at
 * @property string|null $created_user_id
 * @property string|null $updated_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * 
 * @property-read TaxiSession $session
 * @property-read User|null $createdBy
 * @property-read User|null $updatedBy
 */
class SearchSaved extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'session_id',
        'query_data',
        'results_data',
        'saved_at',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'query_data' => 'array',
        'results_data' => 'array',
        'saved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the session for this saved search.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TaxiSession::class, 'session_id');
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
