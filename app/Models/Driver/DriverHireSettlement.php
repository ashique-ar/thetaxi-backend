<?php

namespace App\Models\Driver;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleGroup;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverHireSettlement extends BaseModel
{
    protected $fillable = [
        'booking_id',
        'booking_item_id',
        'driver_id',
        'vehicle_id',
        'vehicle_group_id',
        'driver_log_id',
        'batta_rule_id',
        'batta_category',
        'base_batta_amount',
        'night_count',
        'night_batta_rate',
        'night_batta_amount',
        'manual_adjustment_amount',
        'manual_adjustment_reason',
        'approved_batta_amount',
        'approved_expenses_total',
        'iou_total',
        'final_balance',
        'status',
        'submitted_at',
        'ops_reviewed_at',
        'ops_reviewed_by',
        'ops_review_notes',
        'accounts_finalized_at',
        'accounts_finalized_by',
        'accounts_notes',
        'paid_at',
        'recovered_at',
        'rejection_reason',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'base_batta_amount' => 'decimal:2',
        'night_batta_rate' => 'decimal:2',
        'night_batta_amount' => 'decimal:2',
        'manual_adjustment_amount' => 'decimal:2',
        'approved_batta_amount' => 'decimal:2',
        'approved_expenses_total' => 'decimal:2',
        'iou_total' => 'decimal:2',
        'final_balance' => 'decimal:2',
        'night_count' => 'integer',
        'submitted_at' => 'datetime',
        'ops_reviewed_at' => 'datetime',
        'accounts_finalized_at' => 'datetime',
        'paid_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function bookingItem(): BelongsTo
    {
        return $this->belongsTo(BookingItem::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class);
    }

    public function driverLog(): BelongsTo
    {
        return $this->belongsTo(DriverLog::class, 'driver_log_id');
    }

    public function battaRule(): BelongsTo
    {
        return $this->belongsTo(DriverBattaRule::class, 'batta_rule_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(DriverSettlementExpense::class);
    }

    public function iouAdvances(): HasMany
    {
        return $this->hasMany(DriverIouAdvance::class);
    }
}
