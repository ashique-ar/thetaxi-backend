<?php
// app/Http/Resources/Vehicle/VehicleTransmissionResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleTransmissionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'is_active'   => (bool) $this->is_active,
            'vehicle_count' => (int) ($this->vehicles_count ?? 0),
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
