<?php

// app/Http/Requests/Staff/UpdateStaffRequest.php

namespace App\Http\Requests\Staff;

use App\Models\Staff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function rules()
    {
        $staff = $this->route('staff');
        $id = $staff instanceof Staff ? $staff->getKey() : $staff;

        return [
            'staff_type' => ['sometimes', 'required', 'string', 'max:100'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'status' => ['prohibited'],
            'user_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'collection_commission_enabled' => ['sometimes', 'boolean'],
            'collection_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'code' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('staff', 'code')->ignore($id)],
            'nic' => ['sometimes', 'nullable', 'string', 'max:20'],
            'dob' => ['sometimes', 'nullable', 'date'],
            'license_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'license_expiry' => ['sometimes', 'nullable', 'date', 'after_or_equal:dob'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:30'],
            'addresses' => ['sometimes', 'array', 'max:50'],
            'addresses.*.id' => ['nullable', 'uuid'], 'addresses.*.label' => ['nullable', 'string', 'max:100'],
            'addresses.*.type' => ['required', 'in:home,office,billing,other'], 'addresses.*.address' => ['required', 'string', 'max:2000'],
            'addresses.*.city' => ['nullable', 'string', 'max:255'], 'addresses.*.state_id' => ['nullable', 'exists:states,id'],
            'addresses.*.country_id' => ['nullable', 'exists:countries,id'], 'addresses.*.postal_code' => ['nullable', 'string', 'max:20'],
            'addresses.*.state_name' => ['nullable', 'string', 'max:255'], 'addresses.*.country_name' => ['nullable', 'string', 'max:255'],
            'addresses.*.latitude' => ['nullable', 'numeric', 'between:-90,90'], 'addresses.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'addresses.*.is_default' => ['nullable', 'boolean'],
            'department' => ['sometimes', 'nullable', 'string', 'max:100'],
            'position' => ['sometimes', 'nullable', 'string', 'max:100'],
            'joining_date' => ['sometimes', 'nullable', 'date'],
            'reporting_to' => ['sometimes', 'nullable', 'exists:users,id'],
            'emergency_contact' => ['sometimes', 'nullable', 'array'],
            'payment_methods' => ['sometimes', 'nullable', 'array'],
            'payment_methods.*.id' => ['nullable', 'uuid'],
            'payment_methods.*.method_type' => ['required_with:payment_methods', 'string', 'in:cash,bank_transfer,cheque,card,wallet,online,other'],
            'payment_methods.*.label' => ['nullable', 'string', 'max:120'],
            'payment_methods.*.account_holder_name' => ['nullable', 'string', 'max:255'],
            'payment_methods.*.bank_name' => ['nullable', 'string', 'max:255'],
            'payment_methods.*.bank_branch' => ['nullable', 'string', 'max:255'],
            'payment_methods.*.account_number' => ['nullable', 'string', 'max:100'],
            'payment_methods.*.routing_number' => ['nullable', 'string', 'max:100'],
            'payment_methods.*.is_default' => ['nullable', 'boolean'],
            'payment_methods.*.is_active' => ['nullable', 'boolean'],
        ];
    }
}
