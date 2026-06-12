<?php

namespace App\Models\Driver;

use App\Models\BaseModel;
use App\Models\Vehicle\VehicleGroup;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverBattaRule extends BaseModel
{
    protected $fillable = [
        'vehicle_group_id',
        'batta_category',
        'base_amount',
        'night_amount',
        'effective_from',
        'effective_to',
        'is_active',
        'notes',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'night_amount' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function vehicleGroup(): BelongsTo
    {
        return $this->belongsTo(VehicleGroup::class, 'vehicle_group_id');
    }
}
