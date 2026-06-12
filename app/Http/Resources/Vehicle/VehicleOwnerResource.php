<?php
// app/Http/Resources/Vehicle/VehicleOwnerResource.php

namespace App\Http\Resources\Vehicle;

use App\Http\Resources\UserResource;
use App\Http\Resources\PaymentMethodResource;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleOwnerResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'owner_type_id' => $this->owner_type_id,
            'driver_id' => $this->driver_id,
            'nic' => $this->nic,
            'address' => $this->address,
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city' => $this->city,
            'first_name' => $this->user?->first_name,
            'last_name' => $this->user?->last_name,
            'email' => $this->user?->email,
            'phone' => $this->user?->phone,
            'user' => new UserResource($this->whenLoaded('user')),
            'driver' => $this->whenLoaded('driver'),
            'ownerType' => $this->whenLoaded('type'),
            'type' => $this->whenLoaded('type'),
            'payment_methods' => PaymentMethodResource::collection($this->whenLoaded('paymentMethods')),
            'postal_code' => $this->postal_code,
            'license_number' => $this->license_number,
            'license_expiry' => $this->license_expiry,
            'dob' => $this->dob,
            'notes' => $this->notes,
        ];
    }
}
