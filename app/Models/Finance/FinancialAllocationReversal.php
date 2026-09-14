<?php

namespace App\Models\Finance;

use App\Models\BaseModel;

class FinancialAllocationReversal extends BaseModel
{
    protected $useUserTracking = false;
    protected $fillable = ['corporate_remittance_allocation_id', 'amount', 'reason', 'reversed_by', 'reversed_at'];
    protected $casts = ['amount' => 'decimal:2', 'reversed_at' => 'datetime'];
}
