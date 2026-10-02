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
        $viewer = $request->user();
        $canViewSensitivePersonal = $viewer
            && ($viewer->id === $this->user_id || $viewer->can('staff-sensitive-personal.view'));

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
            'employee_id' => $this->code,
            'role_id' => $staffType,
            'role' => $staffType ? [
                'id' => $staffType,
                'name' => $staffType,
                'display_name' => $staffType,
            ] : null,
            'nic' => $canViewSensitivePersonal ? $this->nic : null,
            'dob' => $canViewSensitivePersonal ? $this->dob : null,
            'date_of_birth' => $canViewSensitivePersonal ? $this->dob : null,
            'license_no' => $canViewSensitivePersonal ? $this->license_no : null,
            'license_expiry' => $canViewSensitivePersonal ? $this->license_expiry : null,
            'address' => $canViewSensitivePersonal ? $this->address : null,
            'sensitive_personal_restricted' => ! $canViewSensitivePersonal,
            'country_id' => $this->country_id,
            'country' => $this->country?->name,
            'state_id' => $this->state_id,
            'state' => $this->state?->name,
            'city' => $this->city,
            'gender' => $this->gender,
            'postal_code' => $this->postal_code,
            'department' => $this->department,
            'position' => $this->position,
            'joining_date' => $this->joining_date,
            'reporting_to' => $this->reporting_to,
            'emergency_contact' => $canViewSensitivePersonal ? $this->emergency_contact : null,
            'payment_methods' => $canViewSensitivePersonal
                ? PaymentMethodResource::collection($this->whenLoaded('paymentMethods'))
                : [],
            'employment_ended_at' => $this->employment_ended_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
