<?php

namespace App\Models\Finance;

use App\Models\BaseModel;

class CorporateOnlinePaymentAttempt extends BaseModel
{

    protected $fillable = [
        'corporate_id', 'settlement_id', 'order_id', 'invoice_currency', 'invoice_amount',
        'gateway_currency', 'gateway_amount', 'fx_rate_to_lkr', 'gateway_fx_rate_to_lkr',
        'fx_rate_at', 'gateway_payload', 'status', 'gateway_transaction_id', 'paid_at', 'created_user_id',
    ];

    protected $casts = [
        'invoice_amount' => 'decimal:4',
        'gateway_amount' => 'decimal:0',
        'fx_rate_to_lkr' => 'decimal:12',
        'gateway_fx_rate_to_lkr' => 'decimal:12',
        'fx_rate_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function settlement()
    {
        return $this->belongsTo(FinancialAccountSettlement::class, 'settlement_id');
    }
}
