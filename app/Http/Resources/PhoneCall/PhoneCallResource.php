<?php
// app/Http/Resources/PhoneCallResource.php

namespace App\Http\Resources\PhoneCall;

use Illuminate\Http\Resources\Json\JsonResource;

class PhoneCallResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'phone'       => $this->phone,
            'client_name' => $this->client_name,
            'summary'     => $this->summary,
            'call_time'   => $this->call_time,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
