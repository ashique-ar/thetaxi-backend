<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionPayout extends BaseModel
{
    protected $hidden = ['payment_account_snapshot'];
    protected $fillable = [
        'company_id', 'staff_id', 'payout_number', 'amount_lkr', 'payment_method', 'payment_account_snapshot',
        'payment_reference', 'paid_at', 'evidence_file_id', 'status', 'accounting_status', 'paid_by',
        'idempotency_key', 'reverses_payout_id', 'reason',
        'request_payload_checksum',
    ];
    protected $casts = [
        'amount_lkr' => 'decimal:4', 'paid_at' => 'datetime', 'payment_account_snapshot' => 'encrypted:array',
    ];
}
