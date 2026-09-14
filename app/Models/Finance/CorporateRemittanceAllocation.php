<?php
namespace App\Models\Finance;

use App\Models\BaseModel;

class CorporateRemittanceAllocation extends BaseModel
{
    protected $useUserTracking = false;
    protected $fillable = ['remittance_id', 'settlement_id', 'amount'];
    protected $casts = ['amount' => 'decimal:2'];
    public function settlement()
    {
        return $this->belongsTo(FinancialAccountSettlement::class, 'settlement_id');
    }
}
