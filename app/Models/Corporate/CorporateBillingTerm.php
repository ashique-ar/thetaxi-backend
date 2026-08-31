<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporateBillingTerm extends BaseModel
{
    protected $fillable = [
        'corporate_id', 'billing_cycle', 'cutoff_day', 'invoice_day', 'due_days', 'credit_limit', 'currency',
        'billing_name', 'tax_identifier', 'billing_address', 'recipients', 'delivery_preferences',
        'effective_from', 'effective_to', 'is_active', 'created_user_id', 'updated_user_id',
    ];

    protected $casts = [
        'cutoff_day' => 'integer', 'invoice_day' => 'integer', 'due_days' => 'integer',
        'credit_limit' => 'decimal:2', 'recipients' => 'array', 'delivery_preferences' => 'array',
        'effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean',
    ];

    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }
}
