<?php

// app/Http/Resources/StateResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StateResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'country_id' => $this->country_id,
            'name' => $this->name,
            'description' => $this->description,
            'url' => $this->url,
            'lng' => $this->lng,
            'lat' => $this->lat,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
