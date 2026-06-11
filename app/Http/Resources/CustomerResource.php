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
            'first_name'      => $this->user?->first_name,
            'last_name'       => $this->user?->last_name,
            'email'           => $this->user?->email,
            'phone'           => $this->user?->phone,
            'email_verified'  => (bool) $this->user?->email_verified_at,
            'phone_verified'  => (bool) $this->user?->phone_verified_at,
            'status'          => $this->user?->is_active ? 'active' : 'inactive',
            'is_verified'     => (bool) ($this->user?->email_verified_at || $this->user?->phone_verified_at),
            'code'            => $this->code,
            'type'            => $this->type,
            'sub_type'        => $this->sub_type,
            'category'        => $this->category,
            'client_type'     => $this->type,
            'passport_number' => $this->passport_number,
            'nic'             => $this->nic,
            'license_no'      => $this->license_no,
            'license_expiry'  => $this->license_expiry,
            'license_type'    => $this->license_type,
            'driving_license_number' => $this->license_no,
            'driving_license_expiry' => $this->license_expiry,
            'dob'             => $this->dob,
            'gender'          => $this->gender,
            'address'         => $this->address,
            'country_id'      => $this->country_id,
            'country'         => $this->getAttribute('country'),
            'state_id'        => $this->state_id,
            'state'           => $this->state?->name,
            'city'            => $this->city,
            'postal_code'     => $this->postal_code,
            'payment_methods' => PaymentMethodResource::collection($this->whenLoaded('paymentMethods')),
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
