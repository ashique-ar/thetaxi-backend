<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Models\Document;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VehicleLease extends BaseModel
{
    protected $fillable = [
        'vehicle_id', 'finance_provider_id', 'owner_id_at_start',
        'ownership_type_at_start', 'lease_number', 'agreement_number',
        'contract_type', 'title_holder', 'lien_reference',
        'ownership_transfer_required', 'start_date', 'end_date',
        'first_payment_date', 'currency', 'financed_amount',
        'down_payment', 'down_payment_paid_date', 'down_payment_method',
        'down_payment_reference', 'refundable_deposit', 'deposit_paid_amount',
        'deposit_paid_date', 'deposit_payment_method', 'deposit_payment_reference', 'installment_amount',
        'payment_frequency', 'installment_count', 'interest_rate', 'balloon_payment',
        'reminder_days', 'status', 'financial_status', 'activated_at',
        'financially_settled_at', 'expired_at', 'expiry_reminder_sent_at',
        'completed_at', 'closed_at', 'closure_type', 'closure_reference',
        'closure_document_id', 'closure_notes', 'closed_by',
        'terms', 'notes', 'created_user_id', 'updated_user_id',
    ];
    protected $casts = [
        'start_date' => 'date', 'end_date' => 'date', 'first_payment_date' => 'date',
        'financed_amount' => 'decimal:2', 'down_payment' => 'decimal:2',
        'down_payment_paid_date' => 'date',
        'refundable_deposit' => 'decimal:2', 'deposit_paid_amount' => 'decimal:2',
        'deposit_paid_date' => 'date', 'installment_amount' => 'decimal:2',
        'interest_rate' => 'decimal:4', 'balloon_payment' => 'decimal:2',
        'ownership_transfer_required' => 'boolean', 'activated_at' => 'datetime',
        'financially_settled_at' => 'datetime', 'expired_at' => 'datetime',
        'expiry_reminder_sent_at' => 'datetime', 'completed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function financeProvider(): BelongsTo { return $this->belongsTo(VehicleFinanceProvider::class, 'finance_provider_id'); }
    public function ownerAtStart(): BelongsTo { return $this->belongsTo(VehicleOwner::class, 'owner_id_at_start'); }
    public function schedules(): HasMany { return $this->hasMany(VehicleLeaseSchedule::class); }
    public function payments(): HasMany { return $this->hasMany(VehicleLeasePayment::class); }
    public function depositDispositions(): HasMany { return $this->hasMany(VehicleLeaseDepositDisposition::class); }
    public function release(): HasOne { return $this->hasOne(VehicleLeaseRelease::class); }
    public function events(): HasMany { return $this->hasMany(VehicleLeaseEvent::class)->orderByDesc('occurred_at'); }
    public function ownershipHistory(): HasMany { return $this->hasMany(VehicleOwnershipHistory::class); }
    public function documents() { return $this->morphMany(Document::class, 'documentable'); }
}
