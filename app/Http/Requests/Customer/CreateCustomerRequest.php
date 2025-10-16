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
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            "first_name" => "required|string|max:255",
            "last_name" => "required|string|max:255",
            "email" => "required|email|max:255",
            "phone" => "required|string|max:20",
            "wedding_date" => "nullable|date",
            "type" => "required|string|max:50",
            "sub_type" => "nullable|string|max:50",
            "category" => "nullable|string|max:50",
            'code' => ['nullable', 'string', 'max:100', 'unique:customers,code'],
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
                $user = User::where('email', $email)->first();
                
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
