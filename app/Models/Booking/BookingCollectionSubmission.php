<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingCollectionSubmission extends BaseModel
{
    private const IMMUTABLE_SUBMISSION_FIELDS = [
        'company_id', 'booking_id', 'booking_payment_schedule_id', 'submitted_by_sales_profile_id',
        'submitted_by_staff_id', 'submitted_by_user_id',
        'source_amount', 'source_currency', 'payment_method', 'reference', 'received_at',
        'evidence_file_id', 'staff_notes', 'idempotency_key', 'request_payload_checksum',
    ];
    private const DECISION_FIELDS = ['status', 'verification_notes', 'verified_by', 'verified_at', 'booking_payment_receipt_id'];

    protected $fillable = [
        'company_id', 'booking_id', 'booking_payment_schedule_id', 'submitted_by_sales_profile_id',
        'submitted_by_staff_id', 'submitted_by_user_id',
        'source_amount', 'source_currency', 'payment_method', 'reference', 'received_at', 'evidence_file_id',
        'status', 'staff_notes', 'verification_notes', 'verified_by', 'verified_at',
        'booking_payment_receipt_id', 'idempotency_key', 'request_payload_checksum',
    ];

    protected $casts = [
        'source_amount' => 'decimal:4', 'received_at' => 'datetime', 'verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (BookingCollectionSubmission $submission): void {
            foreach (self::IMMUTABLE_SUBMISSION_FIELDS as $field) {
                if ($submission->isDirty($field)) {
                    throw new \LogicException("Collection submission {$field} is immutable.");
                }
            }
            if ($submission->getOriginal('status') !== 'submitted') {
                foreach (self::DECISION_FIELDS as $field) {
                    if ($submission->isDirty($field)) {
                        throw new \LogicException('A collection submission decision is immutable once recorded.');
                    }
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Collection submissions are immutable evidence and cannot be deleted.'));
    }

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function paymentSchedule(): BelongsTo { return $this->belongsTo(BookingPaymentSchedule::class, 'booking_payment_schedule_id'); }
}
