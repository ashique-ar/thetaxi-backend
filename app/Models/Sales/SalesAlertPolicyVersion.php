<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesAlertPolicyVersion extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'version', 'status', 'rules', 'effective_from', 'effective_until', 'prepared_by', 'approved_by', 'approved_at', 'rules_checksum'];
    protected $casts = ['rules' => 'array', 'effective_from' => 'date', 'effective_until' => 'date', 'approved_at' => 'datetime'];
}
