<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingPaymentSchedule extends BaseModel
{
    protected $fillable = [
        'booking_id', 'sequence', 'label', 'period_start', 'period_end',
        'due_date', 'amount', 'status', 'notes', 'reminder_sent_at',
        'overdue_notified_at', 'created_user_id',
    ];
    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date',
        'amount' => 'decimal:2',
        'reminder_sent_at' => 'datetime', 'overdue_notified_at' => 'datetime',
    ];
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function allocations(): HasMany { return $this->hasMany(BookingPaymentScheduleAllocation::class); }
}
