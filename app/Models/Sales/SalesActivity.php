<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesActivity extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'sales_profile_id', 'opportunity_id', 'customer_id', 'inquiry_id', 'booking_id',
        'phone_call_id', 'booking_activity_id', 'activity_type', 'direction', 'subject', 'notes', 'outcome',
        'next_action', 'next_action_at', 'source_system', 'source_reference', 'evidence_file_id',
        'occurred_at', 'created_user_id',
    ];
    protected $casts = ['next_action_at' => 'datetime', 'occurred_at' => 'datetime'];
}
