<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesTargetVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'sales_profile_id', 'source', 'copy_batch_id', 'copied_from_target_id',
        'import_job_id', 'import_row_number',
        'period_start', 'period_end', 'new_sales_target_lkr',
        'eligible_collections_target_lkr', 'status', 'version', 'reason',
        'draft_idempotency_key', 'draft_request_checksum', 'prepared_by', 'prepared_at',
        'approved_by', 'approved_at', 'approval_reason', 'approval_idempotency_key',
        'approval_request_checksum', 'payload_checksum',
    ];
    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'prepared_at' => 'datetime', 'approved_at' => 'datetime',
        'new_sales_target_lkr' => 'decimal:4', 'eligible_collections_target_lkr' => 'decimal:4', 'version' => 'integer',
    ];
    protected $hidden = ['draft_idempotency_key', 'approval_idempotency_key'];
}
