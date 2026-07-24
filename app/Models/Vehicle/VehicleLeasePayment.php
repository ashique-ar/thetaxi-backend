<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleLeasePayment extends BaseModel
{
    protected $fillable = [
        'vehicle_lease_id', 'amount', 'paid_date', 'payment_method', 'reference',
        'idempotency_key', 'status', 'reversed_at', 'reversed_by', 'reversal_reason',
        'notes', 'recorded_by', 'created_user_id', 'updated_user_id',
    ];
    protected $casts = ['amount' => 'decimal:2', 'paid_date' => 'date', 'reversed_at' => 'datetime'];
    public function lease(): BelongsTo { return $this->belongsTo(VehicleLease::class, 'vehicle_lease_id'); }
    public function allocations(): HasMany { return $this->hasMany(VehicleLeasePaymentAllocation::class); }
}
