<?php

namespace App\Models\Vehicle;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleOwnershipHistory extends Model
{
    use HasUuids;

    protected $fillable = [
        'vehicle_id', 'vehicle_lease_id', 'document_id', 'from_owner_id', 'to_owner_id',
        'from_ownership_type', 'to_ownership_type', 'transfer_type',
        'effective_at', 'reference', 'notes', 'performed_by',
    ];

    protected $casts = ['effective_at' => 'datetime'];

    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function lease(): BelongsTo { return $this->belongsTo(VehicleLease::class, 'vehicle_lease_id'); }
    public function document(): BelongsTo { return $this->belongsTo(\App\Models\Document::class); }
    public function fromOwner(): BelongsTo { return $this->belongsTo(VehicleOwner::class, 'from_owner_id'); }
    public function toOwner(): BelongsTo { return $this->belongsTo(VehicleOwner::class, 'to_owner_id'); }
    public function performer(): BelongsTo { return $this->belongsTo(User::class, 'performed_by'); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Vehicle ownership history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Vehicle ownership history is immutable.'));
    }
}
