<?php

namespace App\Models;

use App\Models\Booking\Booking;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends BaseModel
{
    use SoftDeletes;

    protected $table = 'invoices';

    protected $fillable = [
        'invoice_number',
        'booking_id',
        'customer_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_address',
        'currency',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'line_items',
        'status',
        'issue_date',
        'due_date',
        'paid_at',
        'pdf_path',
        'pdf_disk',
        'pdf_generated_at',
        'pdf_last_error',
        'payment_terms',
        'notes',
        'email_sending_at',
        'email_sent_at',
        'email_attempts',
        'email_last_error',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'line_items'      => 'array',
        'subtotal'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'issue_date'      => 'date',
        'due_date'        => 'date',
        'paid_at'         => 'datetime',
        'pdf_generated_at' => 'datetime',
        'email_sending_at' => 'datetime',
        'email_sent_at'   => 'datetime',
        'email_attempts'  => 'integer',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function markPaid(): void
    {
        $this->update(['status' => 'paid', 'paid_at' => now()]);
    }

    public function void(): void
    {
        $this->update(['status' => 'void']);
    }
}
