<?php
// app/Http/Resources/Vehicle/VehicleCommonRateResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleCommonRateResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'service_type_id' => $this->service_type_id,
            'rate_name' => $this->rate_name,
            'rate_value' => $this->rate_value,
            'rate_type' => $this->rate_type,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'valid_from' => $this->valid_from,
            'valid_to' => $this->valid_to,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
