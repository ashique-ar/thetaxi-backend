<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Sales\SalesProfile;

class BookingPaymentSchedule extends BaseModel
{
    protected $fillable = [
        'booking_id', 'sequence', 'label', 'period_start', 'period_end',
        'due_date', 'amount', 'status', 'notes', 'reminder_sent_at',
        'overdue_notified_at', 'created_user_id',
        'company_id', 'schedule_kind', 'reconciliation_role', 'source_amount', 'source_currency', 'lkr_amount',
        'is_collection_target_eligible', 'collection_sales_profile_id', 'revision_number',
        'superseded_at', 'superseded_by_revision_id',
        'booking_payment_schedule_rule_id', 'rule_occurrence_number',
    ];
    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date',
        'amount' => 'decimal:2',
        'reminder_sent_at' => 'datetime', 'overdue_notified_at' => 'datetime',
        'source_amount' => 'decimal:4', 'lkr_amount' => 'decimal:4',
        'is_collection_target_eligible' => 'boolean', 'revision_number' => 'integer', 'superseded_at' => 'datetime',
        'rule_occurrence_number' => 'integer',
    ];
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function allocations(): HasMany { return $this->hasMany(BookingPaymentScheduleAllocation::class); }
    public function collectionSalesProfile(): BelongsTo { return $this->belongsTo(SalesProfile::class, 'collection_sales_profile_id'); }
    public function rollingRule(): BelongsTo { return $this->belongsTo(BookingPaymentScheduleRule::class, 'booking_payment_schedule_rule_id'); }
}
