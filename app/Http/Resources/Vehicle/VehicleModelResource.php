<?php
// app/Http/Resources/Vehicle/VehicleModelResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleModelResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'make_id'     => $this->make_id,
            'name'        => $this->name,
            'description' => $this->description,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
