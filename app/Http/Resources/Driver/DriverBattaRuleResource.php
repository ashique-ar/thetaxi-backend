<?php

namespace App\Http\Resources\Driver;

use App\Http\Resources\Vehicle\VehicleGroupResource;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverBattaRuleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'vehicle_group_id' => $this->vehicle_group_id,
            'batta_category' => $this->batta_category,
            'base_amount' => (float) $this->base_amount,
            'night_amount' => (float) $this->night_amount,
            'effective_from' => $this->effective_from,
            'effective_to' => $this->effective_to,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'vehicle_group' => new VehicleGroupResource($this->whenLoaded('vehicleGroup')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
