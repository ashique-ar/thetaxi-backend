<?php
// app/Http/Requests/Driver/CreateDriverRequest.php
namespace App\Http\Requests\Driver\Driver;

use App\Models\Driver\Driver;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateDriverRequest extends FormRequest
{


    public function rules()
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'code' => ['nullable', 'string', 'max:50', 'unique:drivers,code'],
            'nic' => ['nullable', 'string', 'max:20'],
            'license_no' => ['nullable', 'string', 'max:100'],
            'license_expiry' => ['nullable', 'date'],
            'license_type' => ['nullable', 'string', 'max:50'],
            'dob' => ['nullable', 'date'],
            'address' => ['nullable', 'string'],
            'country_id' => ['nullable', 'uuid'],
            'state_id' => ['nullable', 'uuid'],
            'city' => ['nullable', 'string'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'default_vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'remarks' => ['nullable', 'string'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'blood_group' => ['nullable', 'string', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'medical_conditions' => ['nullable', 'string'],
            'hire_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'availability_status' => ['nullable', 'string', 'in:available,busy,on_break,offline'],
            'status' => ['nullable', 'string', 'in:available,busy,on_break,offline'],
            'payment_method' => ['nullable', 'array'],
            'payment_method.method_type' => ['required_with:payment_method', 'string', 'in:cash,bank_transfer,cheque,card,wallet,online,other'],
            'payment_method.label' => ['nullable', 'string', 'max:120'],
            'payment_method.account_holder_name' => ['nullable', 'string', 'max:255'],
            'payment_method.bank_name' => ['nullable', 'string', 'max:255'],
            'payment_method.bank_branch' => ['nullable', 'string', 'max:255'],
            'payment_method.account_number' => ['nullable', 'string', 'max:100'],
            'payment_method.routing_number' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean']
        ];
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function ($validator) {
            $email = $this->input('email');

            if ($email) {
                $user = User::where('email', $email)->first();

                if ($user) {
                    // Check if user already has driver context
                    $existingContext = UserContext::where('user_id', $user->id)
                        ->where('context_type', 'driver')
                        ->where('is_active', true)
                        ->first();

                    if ($existingContext) {
                        $validator->errors()->add(
                            'email',
                            'This email is already registered as a driver. Please use a different email or contact the administrator.'
                        );
                    }

                    // Also check direct Driver model for backwards compatibility
                    $existingDriver = Driver::where('user_id', $user->id)->first();
                    if ($existingDriver) {
                        $validator->errors()->add(
                            'email',
                            'This email is already registered as a driver. Please use a different email or contact the administrator.'
                        );
                    }
                }
            }
        });
    }
}
