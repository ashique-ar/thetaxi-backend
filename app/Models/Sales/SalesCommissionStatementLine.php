<?php

namespace App\Models\Sales;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCommissionStatementLine extends BaseModel
{
    protected $fillable = [
        'statement_id', 'line_type', 'source_type', 'source_id', 'description', 'gross_lkr',
        'deduction_lkr', 'net_lkr', 'line_status', 'hold_code', 'calculation_snapshot', 'snapshot_checksum',
    ];
    protected $casts = [
        'gross_lkr' => 'decimal:4', 'deduction_lkr' => 'decimal:4', 'net_lkr' => 'decimal:4',
        'calculation_snapshot' => 'array',
    ];
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Statement lines are frozen; use a dispute or later adjustment.'));
        static::deleting(fn () => throw new \LogicException('Statement lines cannot be deleted.'));
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(SalesCommissionStatement::class, 'statement_id');
    }
}
