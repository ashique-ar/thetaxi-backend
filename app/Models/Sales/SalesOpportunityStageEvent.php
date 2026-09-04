<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesOpportunityStageEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'opportunity_id', 'from_stage', 'to_stage', 'from_owner_sales_profile_id', 'to_owner_sales_profile_id',
        'reason_code', 'reason', 'idempotency_key', 'actor_user_id', 'occurred_at', 'snapshot',
    ];
    protected $casts = ['occurred_at' => 'datetime', 'snapshot' => 'array'];
}
