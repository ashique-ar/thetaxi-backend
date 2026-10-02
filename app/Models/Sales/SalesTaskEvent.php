<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesTaskEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'task_id', 'event_type', 'from_status', 'to_status', 'from_owner_sales_profile_id',
        'to_owner_sales_profile_id', 'reason', 'idempotency_key', 'actor_user_id', 'occurred_at',
    ];
    protected $casts = ['occurred_at' => 'datetime'];
}
