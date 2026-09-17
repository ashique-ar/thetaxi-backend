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
            'user_id' => ['nullable', 'required_without:first_name', 'exists:users,id'],
            'first_name' => ['nullable', 'required_without:user_id', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'required_without:user_id', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->input('user_id'))],
            'phone' => ['required', 'string', 'max:30'],
            'staff_type' => ['required', 'string', 'max:100'],
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
