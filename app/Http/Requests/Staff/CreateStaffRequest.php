<?php
// app/Http/Requests/Staff/CreateStaffRequest.php
namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class CreateStaffRequest extends FormRequest
{


    public function rules()
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'staff_type' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:100', 'unique:staff,code'],
            'dob' => ['nullable', 'date'],
            'license_no' => ['nullable', 'string', 'max:100'],
            'license_expiry' => ['nullable', 'date', 'after_or_equal:dob'],
            'address' => ['nullable', 'string'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'city' => ['nullable', 'string'],
            'payment_methods' => ['nullable', 'array'],
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
