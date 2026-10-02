<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionStatementExport extends BaseModel
{
    protected $fillable = [
        'statement_id', 'format', 'disk', 'path', 'file_name', 'file_checksum', 'file_size',
        'generated_by', 'generated_at', 'last_downloaded_at', 'last_downloaded_by', 'idempotency_key',
    ];
    protected $casts = ['file_size' => 'integer', 'generated_at' => 'datetime', 'last_downloaded_at' => 'datetime'];
}
