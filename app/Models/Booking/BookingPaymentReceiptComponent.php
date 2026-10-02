<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPaymentReceiptComponent extends BaseModel
{
    protected $fillable = [
        'receipt_id', 'component_type', 'source_amount', 'lkr_amount', 'is_allocatable',
        'is_collection_target_eligible', 'is_commission_eligible', 'allocated_source_amount', 'adjusted_source_amount',
    ];

    protected $casts = [
        'source_amount' => 'decimal:4', 'lkr_amount' => 'decimal:4', 'allocated_source_amount' => 'decimal:4',
        'adjusted_source_amount' => 'decimal:4', 'is_allocatable' => 'boolean',
        'is_collection_target_eligible' => 'boolean', 'is_commission_eligible' => 'boolean',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(BookingPaymentReceipt::class, 'receipt_id');
    }
}
