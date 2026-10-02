<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesProfileExport extends BaseModel
{
    protected $fillable = [
        'company_id', 'status_filter', 'scope_type', 'scope_profile_ids', 'scope_checksum', 'request_checksum',
        'row_count', 'disk', 'path', 'file_name', 'file_checksum', 'file_size', 'generated_by', 'generated_at',
        'expires_at', 'download_count', 'last_downloaded_at', 'last_downloaded_by', 'idempotency_key',
        'created_user_id', 'updated_user_id',
    ];

    protected $casts = [
        'scope_profile_ids' => 'array',
        'row_count' => 'integer',
        'file_size' => 'integer',
        'download_count' => 'integer',
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_downloaded_at' => 'datetime',
    ];
}
