<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleLeaseDepositDisposition extends BaseModel
{
    protected $fillable = [
        'vehicle_lease_id',
        'disposition_type',
        'amount',
        'transaction_date',
        'payment_method',
        'reference',
        'idempotency_key',
        'status',
        'notes',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'recorded_by',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'reversed_at' => 'datetime',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(VehicleLease::class, 'vehicle_lease_id');
    }
}
