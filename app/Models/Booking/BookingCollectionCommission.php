<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingCollectionCommission extends BaseModel
{
    protected $fillable = [
        'booking_id', 'booking_payment_receipt_id', 'staff_id', 'receipt_amount',
        'eligible_amount', 'commission_rate', 'commission_amount', 'status',
        'ineligibility_reason', 'earned_at', 'paid_at', 'paid_by',
        'payment_reference', 'payout_id', 'notes',
    ];

    protected $casts = [
        'receipt_amount' => 'decimal:2',
        'eligible_amount' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'earned_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function receipt(): BelongsTo { return $this->belongsTo(BookingPaymentReceipt::class, 'booking_payment_receipt_id'); }
    public function staff(): BelongsTo { return $this->belongsTo(Staff::class); }
    public function payout(): BelongsTo { return $this->belongsTo(CollectionCommissionPayout::class, 'payout_id'); }
}
