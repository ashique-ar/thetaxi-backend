<?php

namespace App\Models\Sales;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesTargetCopyBatch extends BaseModel
{
    protected $useUserTracking = false;

    protected $fillable = [
        'company_id', 'source_period_start', 'source_period_end', 'target_period_start',
        'target_period_end', 'selection_snapshot', 'preview_checksum', 'reason', 'prepared_by',
        'prepared_at', 'idempotency_key', 'request_payload_checksum',
    ];

    protected $casts = [
        'source_period_start' => 'date', 'source_period_end' => 'date',
        'target_period_start' => 'date', 'target_period_end' => 'date',
        'selection_snapshot' => 'array', 'prepared_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Sales target copy batches are immutable.'));
        static::deleting(fn () => throw new \LogicException('Sales target copy batches cannot be deleted.'));
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SalesTargetVersion::class, 'copy_batch_id');
    }
}
