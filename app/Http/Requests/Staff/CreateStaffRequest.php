<?php
// app/Http/Requests/Staff/CreateStaffRequest.php
namespace App\Http\Requests\Staff;

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateStaffRequest extends FormRequest
{


    public function rules()
    {
        return [
            'user_id' => ['nullable', 'exists:users,id'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->input('user_id'))],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'in:active,inactive,on_leave'],
            'code' => ['nullable', 'string', 'max:100', 'unique:staff,code'],
            'staff_type' => ['nullable', 'string', 'max:100'],
            'collection_commission_enabled' => ['sometimes', 'boolean'],
            'collection_commission_rate' => ['required_if:collection_commission_enabled,true', 'numeric', 'min:0', 'max:100'],
            'nic' => ['nullable', 'string', 'max:20'],
            'dob' => ['nullable', 'date'],
            'license_no' => ['nullable', 'string', 'max:100'],
            'license_expiry' => ['nullable', 'date', 'after_or_equal:dob'],
            'address' => ['nullable', 'string'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'city' => ['nullable', 'string'],
            'gender' => ['nullable', 'string', 'max:30'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'department' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'joining_date' => ['nullable', 'date'],
            'reporting_to' => ['nullable', 'exists:users,id'],
            'emergency_contact' => ['nullable', 'array'],
            'emergency_contact.name' => ['nullable', 'string', 'max:120'],
            'emergency_contact.relationship' => ['nullable', 'string', 'max:80'],
            'emergency_contact.phone' => ['nullable', 'string', 'max:30'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (!$this->filled('user_id')) return;

            $alreadyStaff = UserContext::where('user_id', $this->input('user_id'))
                ->where('context_type', 'staff')
                ->where('is_active', true)
                ->exists() || Staff::where('user_id', $this->input('user_id'))->exists();

            if ($alreadyStaff) {
                $validator->errors()->add('user_id', 'This user is already registered as staff.');
            }
        });
    }
}
