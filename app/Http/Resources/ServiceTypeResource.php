<?php
// app/Http/Resources/ServiceTypeResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ServiceTypeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'name'           => $this->name,
            'description'    => $this->description,
            'slug'           => $this->slug,
            'thumbnail'      => $this->thumbnail,
            'type'           => $this->type,
            'priority'       => $this->priority,
            'minimum_km'     => $this->minimum_km,
            'is_internal'    => $this->is_internal,
            'is_active'      => $this->is_active,
            'terms'          => $this->terms,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
