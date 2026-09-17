<?php
// app/Http/Resources/StaffResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    public function toArray($request)
    {
        $fullName = trim(($this->user?->first_name ?? '') . ' ' . ($this->user?->last_name ?? ''));
        $staffType = $this->staff_type;

        return [
            'id'              => $this->id,
            'user_id'         => $this->user_id,
            'user'            => new UserResource($this->whenLoaded('user')),
            'first_name'      => $this->user?->first_name,
            'last_name'       => $this->user?->last_name,
            'full_name'       => $fullName !== '' ? $fullName : ($this->code ?? 'Staff'),
            'email'           => $this->user?->email,
            'phone'           => $this->user?->phone,
            'status'          => $this->user?->is_active ? 'active' : 'inactive',
            'is_active'       => (bool) $this->user?->is_active,
            'staff_type'      => $this->staff_type,
            'collection_commission_enabled' => (bool) $this->collection_commission_enabled,
            'collection_commission_rate' => (float) $this->collection_commission_rate,
            'role_id'         => $staffType,
            'role'            => $staffType ? [
                'id' => $staffType,
                'name' => $staffType,
                'display_name' => $staffType,
            ] : null,
            'code'            => $this->code,
            'employee_id'     => $this->code,
            'nic'             => $this->nic,
            'dob'             => $this->dob,
            'date_of_birth'   => $this->dob,
            'license_no'      => $this->license_no,
            'license_expiry'  => $this->license_expiry,
            'address'         => $this->address,
            'country_id'      => $this->country_id,
            'country'         => $this->country?->name,
            'state_id'        => $this->state_id,
            'state'           => $this->state?->name,
            'city'            => $this->city,
            'gender'          => $this->gender,
            'postal_code'     => $this->postal_code,
            'department'      => $this->department,
            'position'        => $this->position,
            'joining_date'    => $this->joining_date,
            'reporting_to'    => $this->reporting_to,
            'emergency_contact' => $this->emergency_contact,
            'payment_methods' => PaymentMethodResource::collection($this->whenLoaded('paymentMethods')),
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
