<?php
namespace App\Models\Finance;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialSettlementItem extends BaseModel
{
    protected $fillable = ['settlement_id','booking_id','booking_item_ids','source_snapshot','charge_amount','paid_before_amount','refund_amount','adjustment_amount','allocated_amount','outstanding_amount','status'];
    protected $casts = ['booking_item_ids'=>'array','source_snapshot'=>'array','charge_amount'=>'decimal:2','paid_before_amount'=>'decimal:2','refund_amount'=>'decimal:2','adjustment_amount'=>'decimal:2','allocated_amount'=>'decimal:2','outstanding_amount'=>'decimal:2'];
    public function settlement(): BelongsTo { return $this->belongsTo(FinancialAccountSettlement::class, 'settlement_id'); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
}
