<?php
// app/Http/Resources/Vehicle/VehicleAddonResource.php

namespace App\Http\Resources\Vehicle\VehiclePricing;

use Illuminate\Http\Resources\Json\JsonResource;

class VehiclePricingCommonRateDefinitionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'service_type_id' => $this->service_type_id,
            'vehicle_group_id' => $this->vehicle_group_id,
            'name' => $this->name,
            'description' => $this->description,
            'common_rate_type' => $this->common_rate_type,
            'value' => $this->value,
            'is_mandatory' => $this->is_mandatory,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}