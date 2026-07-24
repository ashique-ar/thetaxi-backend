<?php

namespace App\Models\Vehicle;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleLeaseEvent extends Model
{
    use HasUuids;
    protected $fillable = [
        'vehicle_lease_id', 'event_type', 'from_status', 'to_status',
        'data', 'occurred_at', 'performed_by',
    ];
    protected $casts = ['data' => 'array', 'occurred_at' => 'datetime'];
    public function lease(): BelongsTo { return $this->belongsTo(VehicleLease::class, 'vehicle_lease_id'); }
    public function performer(): BelongsTo { return $this->belongsTo(User::class, 'performed_by'); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Vehicle lease history events are immutable.'));
        static::deleting(fn () => throw new \LogicException('Vehicle lease history events are immutable.'));
    }
}
