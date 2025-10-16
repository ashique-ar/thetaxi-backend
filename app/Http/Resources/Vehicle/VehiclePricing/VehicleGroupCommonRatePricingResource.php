<?php
// app/Http/Resources/Vehicle/VehicleAddonResource.php

namespace App\Http\Resources\Vehicle\VehiclePricing;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleGroupCommonRatePricingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'service_type_id' => $this->service_type_id,
            'vehicle_group_id' => $this->vehicle_group_id,
            'value' => $this->value,            
            'is_active' => $this->is_active,
            'created_user_id' => $this->created_user_id,
            'updated_user_id' => $this->updated_user_id
        ];
    }
}