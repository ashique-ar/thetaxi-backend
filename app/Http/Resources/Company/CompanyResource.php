<?php
// app/Http/Resources/Company/CompanyResource.php
namespace App\Http\Resources\Company;

use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'domain' => $this->domain,
            'description' => $this->description,
            'region_id' => $this->region_id,
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city' => $this->city,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'region' => $this->whenLoaded('region'),
            'country' => $this->whenLoaded('country'),
            'state' => $this->whenLoaded('state'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
