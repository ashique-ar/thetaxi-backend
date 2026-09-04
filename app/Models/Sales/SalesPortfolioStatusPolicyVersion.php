<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesPortfolioStatusPolicyVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'version', 'active_booking_statuses', 'effective_from', 'effective_until',
        'status', 'reason', 'idempotency_key', 'request_checksum', 'prepared_by',
        'approved_by', 'approved_at', 'approval_idempotency_key',
    ];

    protected $casts = [
        'active_booking_statuses' => 'array',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'approved_at' => 'datetime',
        'version' => 'integer',
    ];

    protected $hidden = ['idempotency_key', 'approval_idempotency_key'];
}
