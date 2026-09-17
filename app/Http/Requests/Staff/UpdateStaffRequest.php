<?php
// app/Http/Requests/Staff/UpdateStaffRequest.php
namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffRequest extends FormRequest
{


    public function rules()
    {
        $id = $this->route('staff')->id;

        return [
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'status' => ['sometimes', 'in:active,inactive,on_leave'],
            'user_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'staff_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'collection_commission_enabled' => ['sometimes', 'boolean'],
            'collection_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'code' => ["sometimes", "nullable", "string", "max:100", "unique:staff,code,{$id}"],
            'nic' => ['sometimes', 'nullable', 'string', 'max:20'],
            'dob' => ['sometimes', 'nullable', 'date'],
            'license_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'license_expiry' => ['sometimes', 'nullable', 'date', 'after_or_equal:dob'],
            'address' => ['sometimes', 'nullable', 'string'],
            'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'exists:states,id'],
            'city' => ['sometimes', 'nullable', 'string'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:30'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
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
