<?php
// app/Http/Resources/CustomerResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'              => $this->id,
            'user_id'         => $this->user_id,
            'user'            => new UserResource($this->whenLoaded('user')),
            'code'            => $this->code,
            'client_type'     => $this->client_type,
            'license_no'      => $this->license_no,
            'license_expiry'  => $this->license_expiry,
            'license_type'    => $this->license_type,
            'dob'             => $this->dob,
            'address'         => $this->address,
            'country_id'      => $this->country_id,
            'state_id'        => $this->state_id,
            'city'         => $this->city,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
