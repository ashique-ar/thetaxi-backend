<?php

// app/Http/Resources/StateResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SystemConstantResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'value' => $this->value,
            'description' => $this->description,
        ];
    }
}
