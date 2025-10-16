<?php
// app/Http/Resources/Vehicle/VehicleGroupResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleGroupResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'grade_id'    => $this->grade_id,
            'name'        => $this->name,
            'description' => $this->description,
            'specs'       => $this->specs,
            'images'      => $this->images,

            'vehicle_count'=> $this->vehicles()->count() ?? 0,
            'grade'       => new VehicleGradeResource($this->whenLoaded('grade')),
            'make'        => new VehicleMakeResource($this->whenLoaded('make')),
            'model'       => new VehicleModelResource($this->whenLoaded('model')),
            'transmission' => new VehicleTransmissionResource($this->whenLoaded('transmission')),
            'fuelType'   => new VehicleFuelTypeResource($this->whenLoaded('fuelType')),
            'category'    => new VehicleCategoryResource($this->whenLoaded('category')),
            'class'       => new VehicleClassResource($this->whenLoaded('class')),

        ];
    }
}
