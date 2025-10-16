<?php
// app/Http/Resources/Vehicle/VehicleDistanceMultiplierResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleDistanceMultiplierResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'min_km' => $this->min_km,
            'max_km' => $this->max_km,
            'multiplier' => $this->multiplier,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
