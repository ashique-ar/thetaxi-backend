<?php

namespace App\Models\Finance;

use App\Models\BaseModel;

class StaffPaymentLink extends BaseModel
{
    protected $fillable = [
        'id', 'created_user_id', 'order_id', 'amount', 'currency', 'gateway_amount', 'gateway_currency',
        'fx_rate_to_lkr', 'gateway_fx_rate_to_lkr', 'fx_rate_at', 'gateway_payload', 'status',
        'gateway_transaction_id', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4', 'gateway_amount' => 'decimal:0',
        'fx_rate_to_lkr' => 'decimal:12', 'gateway_fx_rate_to_lkr' => 'decimal:12',
        'fx_rate_at' => 'datetime', 'paid_at' => 'datetime',
    ];
}
