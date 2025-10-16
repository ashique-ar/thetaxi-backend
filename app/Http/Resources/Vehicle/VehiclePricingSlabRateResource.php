<?php
// app/Http/Resources/Vehicle/VehiclePricingSlabRateResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehiclePricingSlabRateResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'              => $this->id,
            'vehicle_group_id'=> $this->vehicle_group_id,
            'pricing_slab_id' => $this->pricing_slab_id,
            'rate'            => $this->rate,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
