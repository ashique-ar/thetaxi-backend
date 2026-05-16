<?php
// app/Http/Resources/ServiceTypeResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ServiceTypeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'context' => $this->context,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'parent_service_type_id' => $this->parent_service_type_id,
            'description' => $this->description,
            'slug' => $this->slug,
            'thumbnail' => $this->thumbnail,
            'type' => $this->type,
            'priority' => $this->priority,
            'minimum_km' => $this->minimum_km,
            'pricing_mode' => $this->pricing_mode,
            'uses_dropoff_time' => (bool) $this->uses_dropoff_time,
            'allow_return_trip' => (bool) $this->allow_return_trip,
            'allow_multiple_pickup_locations' => (bool) $this->allow_multiple_pickup_locations,
            'allow_multiple_dropoff_locations' => (bool) $this->allow_multiple_dropoff_locations,
            'frontend_category' => $this->frontend_category,
            'is_internal' => $this->is_internal,
            'is_active' => $this->is_active,
            'is_public' => $this->context === 'public',
            'is_portal' => $this->context === 'portal',
            'is_corporate' => $this->context === 'corporate',
            'terms' => $this->terms,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
