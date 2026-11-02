<?php

namespace App\Models\Vehicle\VehiclePricing\Concerns;

use App\Models\Vehicle\VehicleClass;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

trait HasVehiclePricingTarget
{
    protected static function bootHasVehiclePricingTarget(): void
    {
        static::saving(function ($pricing): void {
            if ((bool) $pricing->vehicle_class_id === (bool) $pricing->vehicle_group_id) {
                throw ValidationException::withMessages([
                    'vehicle_class_id' => 'A rate must target exactly one vehicle group or vehicle class.',
                ]);
            }
        });
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class, 'vehicle_class_id');
    }
}
