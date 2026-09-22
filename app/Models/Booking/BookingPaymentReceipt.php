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
        'payer_id', 'driver_id', 'idempotency_key', 'company_id', 'source_amount',
        'source_currency', 'lkr_amount', 'fx_rate_to_lkr', 'fx_rate_at', 'fx_source',
        'initial_finality_status', 'provider_event_id', 'provider_payload_checksum',
        'request_payload_checksum', 'corporate_remittance_id',
    ];
    protected $fillable = [
        'booking_id', 'amount', 'refunded_amount', 'payment_method', 'payment_stage', 'payment_purpose', 'reference', 'idempotency_key',
        'received_at', 'received_by', 'notes', 'metadata', 'received_via', 'payer_type', 'payer_id', 'corporate_remittance_id',
        'driver_id', 'allocated_amount', 'driver_company_settled_amount', 'allocation_status', 'driver_company_settlement_status', 'driver_company_settled_at',
        'company_id', 'source_amount', 'source_currency', 'lkr_amount', 'fx_rate_to_lkr', 'fx_rate_at',
        'fx_source', 'initial_finality_status', 'finality_status', 'finalized_at', 'provider_event_id', 'provider_payload_checksum',
        'request_payload_checksum', 'event_version',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'received_at' => 'datetime',
        'metadata' => 'array',
        'allocated_amount' => 'decimal:2',
        'driver_company_settled_amount' => 'decimal:2',
        'driver_company_settled_at' => 'datetime',
        'source_amount' => 'decimal:4',
        'lkr_amount' => 'decimal:4',
        'fx_rate_to_lkr' => 'decimal:10',
        'fx_rate_at' => 'datetime',
        'finalized_at' => 'datetime',
        'event_version' => 'integer',
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
    public function components(): HasMany { return $this->hasMany(BookingPaymentReceiptComponent::class, 'receipt_id'); }
    public function adjustments(): HasMany { return $this->hasMany(BookingPaymentAdjustment::class, 'receipt_id'); }
}
