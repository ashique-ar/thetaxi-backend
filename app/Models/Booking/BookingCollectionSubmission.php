<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingCollectionSubmission extends BaseModel
{
    protected $fillable = [
        'company_id', 'booking_id', 'booking_payment_schedule_id', 'submitted_by_sales_profile_id',
        'source_amount', 'source_currency', 'payment_method', 'reference', 'received_at', 'evidence_file_id',
        'status', 'staff_notes', 'verification_notes', 'verified_by', 'verified_at',
        'booking_payment_receipt_id', 'idempotency_key', 'request_payload_checksum',
    ];

    protected $casts = [
        'source_amount' => 'decimal:4', 'received_at' => 'datetime', 'verified_at' => 'datetime',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function paymentSchedule(): BelongsTo { return $this->belongsTo(BookingPaymentSchedule::class, 'booking_payment_schedule_id'); }
}
