<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesPerformanceAlert extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'sales_profile_id', 'snapshot_id', 'policy_version_id', 'alert_type', 'severity', 'status',
        'explanation', 'evidence_snapshot', 'detected_at', 'assigned_to', 'acknowledged_at', 'resolved_at',
        'resolution_note', 'deduplication_key', 'policy_contract_snapshot', 'threshold_snapshot',
        'comparison_snapshot', 'evaluation_checksum', 'event_version', 'snoozed_until',
        'escalation_level', 'escalated_at', 'last_action_at',
    ];
    protected $casts = [
        'evidence_snapshot' => 'array', 'policy_contract_snapshot' => 'array', 'threshold_snapshot' => 'array',
        'comparison_snapshot' => 'array', 'detected_at' => 'datetime', 'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime', 'event_version' => 'integer', 'snoozed_until' => 'datetime',
        'escalation_level' => 'integer', 'escalated_at' => 'datetime', 'last_action_at' => 'datetime',
    ];
}
