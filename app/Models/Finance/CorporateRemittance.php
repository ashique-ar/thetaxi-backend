<?php
namespace App\Models\Finance;

use App\Models\BaseModel;

class CorporateRemittance extends BaseModel
{
    protected $useUserTracking = false;
    protected $fillable = ['corporate_id', 'reference', 'idempotency_key', 'currency', 'payment_method', 'amount', 'allocated_amount', 'unapplied_amount', 'received_at', 'notes', 'received_by'];
    protected $casts = ['amount' => 'decimal:2', 'allocated_amount' => 'decimal:2', 'unapplied_amount' => 'decimal:2', 'received_at' => 'datetime'];
    public function allocations()
    {
        return $this->hasMany(CorporateRemittanceAllocation::class, 'remittance_id');
    }
}
