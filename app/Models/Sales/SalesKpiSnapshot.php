<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesKpiSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'period_lock_id', 'supersedes_snapshot_id', 'period_start', 'period_end', 'period_type',
        'cutoff_at', 'status', 'generation_kind', 'version', 'ranking_policy_snapshot',
        'source_reconciliation_snapshot', 'source_reconciliation_checksum', 'generation_idempotency_key',
        'generation_payload_checksum', 'snapshot_checksum', 'generated_by', 'generated_at',
    ];
    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'cutoff_at' => 'datetime',
        'generated_at' => 'datetime', 'ranking_policy_snapshot' => 'array',
        'source_reconciliation_snapshot' => 'array', 'version' => 'integer',
    ];
    public function rows(): HasMany { return $this->hasMany(SalesKpiSnapshotRow::class, 'snapshot_id'); }
}
