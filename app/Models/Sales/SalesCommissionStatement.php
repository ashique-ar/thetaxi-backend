<?php

namespace App\Models\Sales;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesCommissionStatement extends BaseModel
{
    protected $fillable = [
        'company_id', 'staff_id', 'sales_profile_id', 'cycle_version_id', 'cycle_assignment_id', 'business_calendar_id',
        'statement_number', 'version', 'period_start', 'period_end', 'cutoff_at', 'finalization_at',
        'approval_deadline_at', 'settlement_at', 'cycle_schedule_snapshot', 'cycle_schedule_checksum', 'timezone', 'payout_currency',
        'opening_carry_forward_lkr', 'gross_earnings_lkr', 'adjustment_credits_lkr', 'recovery_deductions_lkr', 'other_deductions_lkr',
        'contested_hold_lkr', 'net_payable_lkr', 'paid_lkr', 'closing_carry_forward_lkr', 'status', 'state_version',
        'prepared_by', 'prepared_at', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'generation_idempotency_key', 'generation_payload_checksum',
        'voided_by', 'voided_at', 'void_reason',
    ];
    protected $casts = [
        'version' => 'integer', 'state_version' => 'integer', 'period_start' => 'date', 'period_end' => 'date',
        'cutoff_at' => 'datetime', 'finalization_at' => 'datetime', 'approval_deadline_at' => 'datetime',
        'settlement_at' => 'datetime', 'cycle_schedule_snapshot' => 'array', 'prepared_at' => 'datetime', 'submitted_at' => 'datetime',
        'approved_at' => 'datetime', 'voided_at' => 'datetime',
        'opening_carry_forward_lkr' => 'decimal:4', 'gross_earnings_lkr' => 'decimal:4',
        'adjustment_credits_lkr' => 'decimal:4',
        'recovery_deductions_lkr' => 'decimal:4', 'other_deductions_lkr' => 'decimal:4',
        'contested_hold_lkr' => 'decimal:4', 'net_payable_lkr' => 'decimal:4',
        'paid_lkr' => 'decimal:4', 'closing_carry_forward_lkr' => 'decimal:4',
    ];
    public function lines(): HasMany { return $this->hasMany(SalesCommissionStatementLine::class, 'statement_id'); }
}
