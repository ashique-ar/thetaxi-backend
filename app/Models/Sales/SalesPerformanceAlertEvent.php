<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesPerformanceAlertEvent extends Model
{
    use HasUuids;

    protected $fillable = ['alert_id', 'from_status', 'to_status', 'note', 'idempotency_key', 'actor_user_id', 'occurred_at'];
    protected $casts = ['occurred_at' => 'datetime'];
}
