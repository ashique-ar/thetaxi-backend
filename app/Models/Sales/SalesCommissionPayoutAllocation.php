<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionPayoutAllocation extends BaseModel
{
    protected $fillable = ['payout_id', 'statement_id', 'amount_lkr'];
    protected $casts = ['amount_lkr' => 'decimal:4'];
}
