<?php
namespace App\Models\Finance;
use App\Models\BaseModel;
class FinancialAdjustment extends BaseModel
{
    protected $fillable = ['settlement_id', 'settlement_item_id', 'booking_id', 'type', 'amount', 'reason', 'reference', 'metadata', 'created_user_id'];
    protected $casts = ['amount' => 'decimal:2', 'metadata' => 'array'];
    public function settlement() { return $this->belongsTo(FinancialAccountSettlement::class, 'settlement_id'); }
    public function booking() { return $this->belongsTo(\App\Models\Booking\Booking::class); }

    protected static function booted(): void
    {
        static::updating(fn() => throw new \LogicException('Financial adjustments are immutable. Record a reversing adjustment instead.'));
        static::deleting(fn() => throw new \LogicException('Financial adjustments cannot be deleted. Record a reversing adjustment instead.'));
    }
}
