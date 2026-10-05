<?php

namespace App\Models\Sales;

use App\Models\NonSoftDeletableModel;

class SalesCommissionPayoutAllocation extends NonSoftDeletableModel
{
    protected $fillable = ['payout_id', 'statement_id', 'amount_lkr'];
    protected $casts = ['amount_lkr' => 'decimal:4'];
}
