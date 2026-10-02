<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTask extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'owner_sales_profile_id', 'opportunity_id', 'customer_id', 'inquiry_id', 'booking_id',
        'title', 'description', 'priority', 'status', 'due_at', 'remind_at', 'reminder_dispatched_at',
        'escalate_at', 'deadline_contract_version', 'deadline_snapshot', 'deadline_checksum',
        'escalated_at', 'completed_at', 'state_version', 'created_user_id', 'updated_user_id',
    ];
    protected $casts = [
        'due_at' => 'datetime', 'remind_at' => 'datetime', 'reminder_dispatched_at' => 'datetime',
        'escalate_at' => 'datetime', 'deadline_snapshot' => 'array', 'escalated_at' => 'datetime',
        'completed_at' => 'datetime', 'state_version' => 'integer',
    ];
    public function owner(): BelongsTo { return $this->belongsTo(SalesProfile::class, 'owner_sales_profile_id')->with('staff.user'); }
}
