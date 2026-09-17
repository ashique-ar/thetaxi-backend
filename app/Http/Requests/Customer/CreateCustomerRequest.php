<?php
// app/Http/Requests/Customer/CreateCustomerRequest.php
namespace App\Http\Requests\Customer;

use App\Models\User;
use App\Models\UserContext;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateCustomerRequest extends FormRequest
{

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }

    public function rules()
    {
        return [
            "user_id" => "nullable|exists:users,id",
            "first_name" => "nullable|string|max:255",
            "last_name" => "nullable|string|max:255",
            "email" => "nullable|email|max:255",
            "phone" => "required|string|max:20",
            'code' => ['nullable', 'string', 'max:100', 'unique:customers,code'],
            "wedding_date" => "nullable|date",
            "type" => "nullable|string|max:50",
            "sub_type" => "nullable|string|max:50",
            "category" => "nullable|string|max:50",
            'nic' => ['nullable', 'string', 'max:100'],
            'passport_number' => ['nullable', 'string', 'max:100'],
            'license_no' => ['nullable', 'string', 'max:100'],
            'license_expiry' => ['nullable', 'date'],
            'license_type' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:100'],
            'dob' => ['nullable', 'date'],
            'address' => ['nullable', 'string'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'city' => ['nullable', 'string'],
            'default_payment_arrangement' => ['nullable', 'in:cash_to_driver,online,advance_then_balance,deposit_then_balance,pay_at_end,account_credit,bank_transfer,card,complimentary'],
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

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator)
    {
        $validator->after(function ($validator) {
            $email = $this->input('email');

            if ($email) {
                $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();

                if ($user) {
                    // Check if user already has customer context
                    $existingContext = UserContext::where('user_id', $user->id)
                        ->where('context_type', 'customer')
                        ->where('is_active', true)
                        ->first();

                    if ($existingContext) {
                        $validator->errors()->add(
                            'email',
                            'This email is already registered as a customer. Please use a different email or contact the administrator.'
                        );
                    }

                    // Also check direct Customer model for backwards compatibility
                    $existingCustomer = Customer::where('user_id', $user->id)->first();
                    if ($existingCustomer) {
                        $validator->errors()->add(
                            'email',
                            'This email is already registered as a customer. Please use a different email or contact the administrator.'
                        );
                    }
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages()
    {
        return [
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'phone.required' => 'Phone number is required.',
            'type.required' => 'Customer type is required.',
            'code.unique' => 'This customer code is already taken.',
        ];
    }
}
