<?php

// app/Http/Resources/StaffResource.php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    public function toArray($request)
    {
        $fullName = trim(($this->user?->first_name ?? '').' '.($this->user?->last_name ?? ''));
        $staffType = $this->staff_type;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'company_id' => $this->company_id,
            'company' => $this->company?->name,
            'user' => new UserResource($this->whenLoaded('user')),
            'first_name' => $this->user?->first_name,
            'last_name' => $this->user?->last_name,
            'full_name' => $fullName !== '' ? $fullName : ($this->code ?? 'Staff'),
            'email' => $this->user?->email,
            'phone' => $this->user?->phone,
            'status' => $this->user?->is_active ? 'active' : 'inactive',
            'is_active' => (bool) $this->user?->is_active,
            'staff_type' => $this->staff_type,
            'collection_commission_enabled' => (bool) $this->collection_commission_enabled,
            'collection_commission_rate' => (float) $this->collection_commission_rate,
            'code' => $this->code,
            'nic' => $this->nic,
            'dob' => $this->dob,
            'license_no' => $this->license_no,
            'license_expiry' => $this->license_expiry,
            'address' => $this->address,
            'country_id' => $this->country_id,
            'country' => $this->country?->name,
            'state_id' => $this->state_id,
            'state' => $this->state?->name,
            'city' => $this->city,
            'employment_ended_at' => $this->employment_ended_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
