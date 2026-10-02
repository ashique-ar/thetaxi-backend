<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\Sales\SalesCommissionPayout;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollectionCommissionPayout extends BaseModel
{
    protected $fillable = [
        'payout_number', 'period_start', 'period_end', 'total_amount', 'status',
        'paid_at', 'payment_reference', 'notes', 'paid_by', 'canonical_payout_id',
        'projection_status', 'projected_at', 'projected_by', 'projection_note',
    ];
    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date',
        'total_amount' => 'decimal:2', 'paid_at' => 'datetime', 'projected_at' => 'datetime',
    ];
    public function commissions(): HasMany
    {
        return $this->hasMany(BookingCollectionCommission::class, 'payout_id');
    }

    public function canonicalPayout(): BelongsTo
    {
        return $this->belongsTo(SalesCommissionPayout::class, 'canonical_payout_id');
    }
}
