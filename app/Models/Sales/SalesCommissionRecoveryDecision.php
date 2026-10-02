<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionRecoveryDecision extends BaseModel
{
    protected $fillable = [
        'recovery_case_id', 'decision', 'commission_adjustment_lkr', 'waived_recovery_lkr',
        'resolution_disposition', 'source_statement_id', 'source_statement_line_id', 'source_statement_status',
        'source_statement_paid_lkr', 'source_statement_net_payable_lkr', 'decision_preview_snapshot',
        'decision_preview_checksum', 'reason', 'approved_by', 'approved_at', 'idempotency_key', 'request_payload_checksum',
    ];
    protected $casts = [
        'commission_adjustment_lkr' => 'decimal:4', 'waived_recovery_lkr' => 'decimal:4',
        'source_statement_paid_lkr' => 'decimal:4', 'source_statement_net_payable_lkr' => 'decimal:4',
        'decision_preview_snapshot' => 'array', 'approved_at' => 'datetime',
    ];
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Commission recovery decisions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Commission recovery decisions cannot be deleted.'));
    }
}
