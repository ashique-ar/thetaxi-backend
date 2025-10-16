<?php
// app/Http/Resources/Vehicle/VehiclePricingSlabResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehiclePricingSlabResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'              => $this->id,
            'service_type_id' => $this->service_type_id,
            'min_days'        => $this->min_days,
            'max_days'        => $this->max_days,
            'base_rate'       => $this->base_rate,
            'rate_type'       => $this->rate_type,
            'extra_rate'      => $this->extra_rate,
            'region_id'       => $this->region_id,
            'valid_from'      => $this->valid_from,
            'valid_to'        => $this->valid_to,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
