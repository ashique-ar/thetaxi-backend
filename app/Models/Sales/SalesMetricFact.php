<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesMetricFact extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'sales_profile_id', 'metric_type', 'business_classification', 'quantity', 'amount_lkr',
        'occurred_on', 'occurred_at', 'source_type', 'source_id', 'source_event', 'dimensions', 'fact_checksum',
    ];
    protected $casts = [
        'occurred_on' => 'date', 'occurred_at' => 'datetime', 'dimensions' => 'array',
        'quantity' => 'decimal:4', 'amount_lkr' => 'decimal:4',
    ];
}
