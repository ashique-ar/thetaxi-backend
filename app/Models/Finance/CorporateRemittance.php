<?php
namespace App\Models\Finance;

use App\Models\BaseModel;

class CorporateRemittance extends BaseModel
{
    protected $useUserTracking = false;
    protected $fillable = ['corporate_id', 'reference', 'idempotency_key', 'currency', 'payment_method', 'amount', 'allocated_amount', 'unapplied_amount', 'received_at', 'notes', 'received_by', 'fx_rate_to_lkr', 'fx_rate_at', 'fx_source'];
    protected $casts = ['amount' => 'decimal:4', 'allocated_amount' => 'decimal:4', 'unapplied_amount' => 'decimal:4', 'fx_rate_to_lkr' => 'decimal:10', 'fx_rate_at' => 'datetime', 'received_at' => 'datetime'];
    public function allocations()
    {
        return $this->hasMany(CorporateRemittanceAllocation::class, 'remittance_id');
    }

    protected static function booted(): void
    {
        static::updating(function (CorporateRemittance $remittance): void {
            foreach (['corporate_id', 'reference', 'idempotency_key', 'currency', 'payment_method', 'amount', 'received_at', 'fx_rate_to_lkr', 'fx_rate_at', 'fx_source'] as $field) {
                if ($remittance->isDirty($field)) {
                    throw new \LogicException("Corporate remittance {$field} is immutable. Record a correcting remittance instead.");
                }
            }
        });
    }
}
