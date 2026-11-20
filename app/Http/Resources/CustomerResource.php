<?php
// app/Http/Resources/CustomerResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray($request)
    {
        if ($request->boolean('booking_lookup')) {
            return [
                'id' => $this->id,
                'user' => $this->whenLoaded('user', fn ($user) => [
                    'first_name' => $user?->first_name,
                    'last_name' => $user?->last_name,
                ]),
            ];
        }

        return [
            'id'              => $this->id,
            'user_id'         => $this->user_id,
            'user'            => new UserResource($this->whenLoaded('user')),
            'first_name'      => $this->user?->first_name,
            'last_name'       => $this->user?->last_name,
            'email'           => $this->user?->email,
            'phone'           => $this->user?->phone,
            'profile_image'   => proxy_s3_urls($this->user?->profile_image),
            'email_verified'  => (bool) $this->user?->email_verified_at,
            'phone_verified'  => (bool) $this->user?->phone_verified_at,
            'possible_phone_match' => (bool) $this->possible_phone_match,
            'possible_duplicate' => (bool) $this->possible_duplicate,
            'customer_lifecycle' => $this->when(isset($this->completed_booking_count), (int) $this->completed_booking_count > 0 ? 'returning' : 'no_completed_booking'),
            'completed_booking_count' => $this->when(isset($this->completed_booking_count), (int) $this->completed_booking_count),
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
            'addresses'       => $this->whenLoaded('addresses', fn () => $this->addresses->map(fn ($address) => [
                'id' => $address->id, 'label' => $address->label, 'type' => $address->type,
                'address' => $address->address, 'city' => $address->city, 'state_id' => $address->state_id,
                'country_id' => $address->country_id, 'state' => $address->state?->name ?? $address->state_name,
                'country' => $address->country?->name ?? $address->country_name,
                'postal_code' => $address->postal_code, 'latitude' => $address->latitude, 'longitude' => $address->longitude, 'is_default' => $address->is_default,
            ])->values()),
            'payment_methods' => $this->when(
                $request->user()?->can('payment-methods.view') && $this->resource->relationLoaded('paymentMethods'),
                fn () => PaymentMethodResource::collection($this->paymentMethods)
            ),
            'default_payment_arrangement' => $this->default_payment_arrangement ?: 'account_credit',
            'relationship_owner' => $this->when(isset($this->relationship_owner), $this->relationship_owner),
            'relationship_owner_history' => $this->when(isset($this->relationship_owner_history), $this->relationship_owner_history),
            'relationship_owner_name' => $this->relationship_owner_name,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
