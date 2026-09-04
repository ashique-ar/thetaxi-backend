<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPaymentMethodChange extends BaseModel
{
    protected $fillable = [
        'staff_id',
        'payment_method_id',
        'action',
        'payload',
        'reason',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'applied_at',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'payload' => 'encrypted:array',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }
}
