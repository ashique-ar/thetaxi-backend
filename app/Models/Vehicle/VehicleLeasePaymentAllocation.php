<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleLeasePaymentAllocation extends BaseModel
{
    protected $fillable = [
        'vehicle_lease_payment_id', 'vehicle_lease_schedule_id', 'amount',
        'allocated_at', 'reversed_at', 'reversed_by', 'created_user_id', 'updated_user_id',
    ];
    protected $casts = ['amount' => 'decimal:2', 'allocated_at' => 'datetime', 'reversed_at' => 'datetime'];
    public function payment(): BelongsTo { return $this->belongsTo(VehicleLeasePayment::class, 'vehicle_lease_payment_id'); }
    public function schedule(): BelongsTo { return $this->belongsTo(VehicleLeaseSchedule::class, 'vehicle_lease_schedule_id'); }
}
