<?php
// app/Http/Resources/Vehicle/VehicleOwnerTypeResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleOwnerTypeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description ?? null,
            'owner_count' => (int) ($this->owners_count ?? 0),
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
