<?php

namespace App\Models\Driver;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverIouAdvance extends BaseModel
{
    protected $fillable = [
        'driver_hire_settlement_id',
        'booking_id',
        'driver_id',
        'amount',
        'currency',
        'issued_date',
        'reference_number',
        'notes',
        'issued_by',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issued_date' => 'date',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(DriverHireSettlement::class, 'driver_hire_settlement_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
