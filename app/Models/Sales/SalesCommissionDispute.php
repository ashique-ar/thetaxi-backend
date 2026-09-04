<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionDispute extends BaseModel
{
    protected $fillable = [
        'company_id', 'statement_id', 'statement_line_id', 'raised_by_staff_id', 'category', 'reason',
        'evidence_file_id', 'contested_amount_lkr', 'status', 'raised_at', 'response_due_at', 'reviewed_by',
        'resolved_at', 'resolution', 'resolution_reason', 'acknowledged_at', 'idempotency_key',
        'resolution_idempotency_key', 'resolution_payload_checksum',
    ];
    protected $casts = [
        'contested_amount_lkr' => 'decimal:4', 'raised_at' => 'datetime', 'response_due_at' => 'datetime',
        'resolved_at' => 'datetime', 'acknowledged_at' => 'datetime',
    ];
}
