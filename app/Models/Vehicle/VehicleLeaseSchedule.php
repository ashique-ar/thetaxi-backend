<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleLeaseSchedule extends BaseModel
{
    protected $fillable = [
        'vehicle_lease_id', 'sequence', 'due_date', 'principal_amount',
        'interest_amount', 'fee_amount', 'amount_due', 'status',
        'reminder_sent_at', 'overdue_notified_at', 'created_user_id', 'updated_user_id',
    ];
    protected $casts = [
        'due_date' => 'date', 'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2', 'fee_amount' => 'decimal:2',
        'amount_due' => 'decimal:2', 'reminder_sent_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
    ];
    public function lease(): BelongsTo { return $this->belongsTo(VehicleLease::class, 'vehicle_lease_id'); }
    public function allocations(): HasMany { return $this->hasMany(VehicleLeasePaymentAllocation::class); }
}
