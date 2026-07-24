<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleLeaseRelease extends BaseModel
{
    protected $fillable = [
        'vehicle_lease_id', 'release_type', 'effective_at', 'odometer',
        'condition_status', 'location', 'released_to', 'outstanding_amount', 'termination_charge',
        'deposit_credit', 'net_settlement_amount', 'settlement_status',
        'settlement_direction', 'settlement_amount', 'settlement_method',
        'reference', 'settlement_reference', 'settlement_idempotency_key',
        'settled_at', 'settled_by',
        'reason', 'condition_notes', 'notes', 'approved_by',
        'created_user_id', 'updated_user_id',
    ];
    protected $casts = [
        'effective_at' => 'datetime', 'outstanding_amount' => 'decimal:2',
        'termination_charge' => 'decimal:2', 'deposit_credit' => 'decimal:2',
        'net_settlement_amount' => 'decimal:2', 'settlement_amount' => 'decimal:2',
        'settled_at' => 'datetime',
    ];
    public function lease(): BelongsTo { return $this->belongsTo(VehicleLease::class, 'vehicle_lease_id'); }
}
