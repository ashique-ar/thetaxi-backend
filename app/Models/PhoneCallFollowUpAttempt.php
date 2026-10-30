<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneCallFollowUpAttempt extends Model
{
    use HasUuids;

    protected $fillable = ['phone_call_id', 'actor_user_id', 'idempotency_key', 'outcome', 'notes', 'attempted_at'];

    protected $casts = ['attempted_at' => 'datetime'];

    public function phoneCall(): BelongsTo
    {
        return $this->belongsTo(PhoneCall::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
