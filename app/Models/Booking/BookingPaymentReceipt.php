<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Driver\Driver;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingPaymentReceipt extends BaseModel
{
    private const IMMUTABLE_EVIDENCE_FIELDS = [
        'booking_id', 'amount', 'payment_method', 'payment_stage', 'payment_purpose',
        'reference', 'received_at', 'received_by', 'received_via', 'payer_type',
        'payer_id', 'driver_id', 'idempotency_key',
    ];
    protected $fillable = [
        'booking_id', 'amount', 'refunded_amount', 'payment_method', 'payment_stage', 'payment_purpose', 'reference', 'idempotency_key',
        'received_at', 'received_by', 'notes', 'metadata', 'received_via', 'payer_type', 'payer_id',
        'driver_id', 'allocated_amount', 'driver_company_settled_amount', 'allocation_status', 'driver_company_settlement_status', 'driver_company_settled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'received_at' => 'datetime',
        'metadata' => 'array',
        'allocated_amount' => 'decimal:2',
        'driver_company_settled_amount' => 'decimal:2',
        'driver_company_settled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (BookingPaymentReceipt $receipt): void {
            foreach (self::IMMUTABLE_EVIDENCE_FIELDS as $field) {
                if ($receipt->isDirty($field)) {
                    throw new \LogicException("Payment receipt {$field} is immutable. Record a refund, allocation, or reversing entry instead.");
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Payment receipts cannot be deleted. Record a reversing entry instead.'));
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function driver(): BelongsTo { return $this->belongsTo(Driver::class); }
    public function collectionCommission() { return $this->hasOne(BookingCollectionCommission::class); }
    public function depositRefunds(): HasMany { return $this->hasMany(BookingDepositRefund::class); }
}
