<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneCallAssignment extends Model
{
    use HasUuids;

    protected $fillable = ['phone_call_id', 'company_id', 'from_user_id', 'to_user_id', 'actor_user_id', 'reason', 'event_at'];
    protected $casts = ['event_at' => 'datetime'];

    public function phoneCall(): BelongsTo { return $this->belongsTo(PhoneCall::class); }
    public function fromUser(): BelongsTo { return $this->belongsTo(User::class, 'from_user_id'); }
    public function toUser(): BelongsTo { return $this->belongsTo(User::class, 'to_user_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
