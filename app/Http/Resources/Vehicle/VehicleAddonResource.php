<?php
// app/Http/Resources/Vehicle/VehicleAddonResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleAddonResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'service_type_id' => $this->service_type_id,
            'name' => $this->name,
            'thumbnail' => $this->thumbnail,
            'min_qty' => $this->min_qty,
            'max_qty' => $this->max_qty,
            'description' => $this->description,
            'amount' => $this->amount,
            'rate_type' => $this->rate_type,
            'valid_from' => $this->valid_from,
            'valid_to' => $this->valid_to,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
