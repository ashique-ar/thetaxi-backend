<?php

namespace App\Models\Sales;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesBookingAttribution extends BaseModel
{
    protected $fillable = [
        'booking_id', 'root_attribution_id', 'company_id', 'acquisition_sales_profile_id', 'collection_sales_profile_id',
        'customer_id', 'commission_category', 'business_classification', 'classification_source',
        'secured_at', 'contract_value_source', 'source_currency', 'contract_value_lkr',
        'fx_rate_to_lkr', 'fx_rate_at', 'new_customer_status', 'status', 'version',
        'created_user_id', 'updated_user_id',
        'commission_plan_family_id', 'commission_plan_assignment_id', 'commission_plan_resolved_at',
    ];

    protected $casts = [
        'secured_at' => 'datetime', 'fx_rate_at' => 'datetime', 'contract_value_source' => 'decimal:4',
        'commission_plan_resolved_at' => 'datetime',
        'contract_value_lkr' => 'decimal:4', 'fx_rate_to_lkr' => 'decimal:10', 'version' => 'integer',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
