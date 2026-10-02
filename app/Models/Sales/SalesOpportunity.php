<?php

namespace App\Models\Sales;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOpportunity extends BaseModel
{
    protected $fillable = [
        'company_id', 'owner_sales_profile_id', 'customer_id', 'inquiry_id', 'source_phone_call_id',
        'opportunity_number', 'name', 'prospect_name', 'prospect_company', 'prospect_email', 'prospect_phone',
        'source', 'campaign', 'referral', 'description', 'stage', 'expected_value_source', 'source_currency',
        'expected_value_lkr', 'probability_percent', 'expected_close_date', 'services', 'customer_needs',
        'competitor_notes', 'next_action', 'next_action_at', 'confidentiality', 'lost_reason_code',
        'lost_reason', 'won_booking_id', 'won_at', 'closed_at', 'state_version', 'created_user_id', 'updated_user_id',
    ];

    protected $casts = [
        'services' => 'array', 'expected_close_date' => 'date', 'next_action_at' => 'datetime',
        'won_at' => 'datetime', 'closed_at' => 'datetime', 'expected_value_source' => 'decimal:4',
        'expected_value_lkr' => 'decimal:4', 'probability_percent' => 'integer', 'state_version' => 'integer',
    ];

    public function owner(): BelongsTo { return $this->belongsTo(SalesProfile::class, 'owner_sales_profile_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function inquiry(): BelongsTo { return $this->belongsTo(Inquiry::class); }
    public function wonBooking(): BelongsTo { return $this->belongsTo(Booking::class, 'won_booking_id'); }
    public function stageEvents(): HasMany { return $this->hasMany(SalesOpportunityStageEvent::class, 'opportunity_id'); }
}
