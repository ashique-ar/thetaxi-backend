<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionStatementEvent extends BaseModel
{
    protected $fillable = ['statement_id', 'from_version', 'to_version', 'from_status', 'to_status', 'reason', 'idempotency_key', 'actor_user_id', 'occurred_at'];
    protected $casts = ['from_version' => 'integer', 'to_version' => 'integer', 'occurred_at' => 'datetime'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Statement events are immutable.'));
        static::deleting(fn () => throw new \LogicException('Statement events cannot be deleted.'));
    }
}
