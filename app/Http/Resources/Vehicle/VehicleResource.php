<?php
// app/Http/Resources/Vehicle/VehicleResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title ?? null,
            'registration_no' => $this->registration_no ?? $this->license_plate ?? null,
            'chasis_no' => $this->chasis_no ?? null,
            'engine_no' => $this->engine_no ?? null,
            'license_plate' => $this->license_plate ?? $this->registration_no ?? null,
            'slug' => $this->slug ?? null,
            'model_year' => $this->model_year ?? null,
            'year' => $this->year ?? null,
            'color' => $this->color ?? null,
            'bags' => $this->bags ?? null,
            'seats' => $this->seats ?? null,
            'owner_id' => $this->owner_id ?? null,
            'ac'=> $this->ac ?? null,            
            'owner' => new VehicleOwnerResource($this->whenLoaded('owner')),
            'vehicle_group_id' => $this->vehicle_group_id ?? null,
            'default_driver_id' => $this->default_driver_id ?? null,
            'group' => new VehicleGroupResource($this->whenLoaded('group')),
            'contract_type' => new VehicleContractTypeResource($this->whenLoaded('contractType')),
            'thumbnail' => $this->thumbnail ?? null,
            'tagline' => $this->tagline ?? null,
            'is_self_driven_compatible' => $this->is_self_driven_compatible ?? null,
            'description' => $this->description ?? null,
        ];
    }
}
