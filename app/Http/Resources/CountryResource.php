<?php

// app/Http/Resources/CountryResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'code3' => $this->code3,
            'callcode' => $this->callcode,
            'googlelode' => $this->googlelode,
            'description' => $this->description,
            'url' => $this->url,
            'tagline' => $this->tagline,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}