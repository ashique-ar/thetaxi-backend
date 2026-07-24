<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollectionCommissionPayout extends BaseModel
{
    protected $fillable = [
        'payout_number', 'period_start', 'period_end', 'total_amount', 'status',
        'paid_at', 'payment_reference', 'notes', 'paid_by',
    ];
    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date',
        'total_amount' => 'decimal:2', 'paid_at' => 'datetime',
    ];
    public function commissions(): HasMany
    {
        return $this->hasMany(BookingCollectionCommission::class, 'payout_id');
    }
}
