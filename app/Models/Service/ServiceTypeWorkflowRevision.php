<?php

namespace App\Models\Service;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTypeWorkflowRevision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'service_type_id', 'revision', 'change_type', 'status', 'workflow_config', 'effective_settings', 'actor_user_id', 'reviewed_by_user_id', 'reviewed_at', 'review_note', 'created_at',
    ];

    protected $casts = [
        'workflow_config' => 'array', 'effective_settings' => 'array', 'created_at' => 'datetime', 'reviewed_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
