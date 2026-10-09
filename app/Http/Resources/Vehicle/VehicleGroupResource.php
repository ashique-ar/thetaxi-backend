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
            'seo_slug' => $this->seo_slug,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'seo_target_terms' => $this->seo_target_terms,
            'seo_og_image' => $this->seo_og_image,
            'service_seo' => $this->service_seo ?? [],
            'specs'       => $this->specs,
            'images'      => $this->images,
            'thumbnail'      => $this->thumbnail,
            'vehicle_count'=> (int) ($this->vehicles_count ?? 0),
            'grade'       => new VehicleGradeResource($this->whenLoaded('grade')),
            'make'        => new VehicleMakeResource($this->whenLoaded('make')),
            'model'       => new VehicleModelResource($this->whenLoaded('model')),
            'transmission' => new VehicleTransmissionResource($this->whenLoaded('transmission')),
            'fuel_type'   => new VehicleFuelTypeResource($this->whenLoaded('fuelType')),
            'category'    => new VehicleCategoryResource($this->whenLoaded('category')),
            'class'       => new VehicleClassResource($this->whenLoaded('class')),
            'is_active' => $this->is_active,
            'is_featured' => $this->is_featured,
            'passengers_count' => $this->passengers_count,
            'hand_luggages' => $this->hand_luggages,
            'air_conditioning' => $this->air_conditioning,
            'refundable_deposit' => $this->refundable_deposit
        ];
    }
}
