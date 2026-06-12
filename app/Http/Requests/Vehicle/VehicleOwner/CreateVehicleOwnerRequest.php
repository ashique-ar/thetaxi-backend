<?php
// app/Http/Requests/Vehicle/VehicleOwner/CreateVehicleOwnerRequest.php

namespace App\Http\Requests\Vehicle\VehicleOwner;

use App\Models\User;
use App\Models\UserContext;
use App\Models\Vehicle\VehicleOwner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateVehicleOwnerRequest extends FormRequest
{


    public function rules()
    {
        return [
            'owner_type_id' => ['required', 'exists:vehicle_owner_types,id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'nic' => ['nullable', 'string', 'max:20'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'city' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'license_number' => ['nullable', 'string'],
            'license_expiry' => ['nullable', 'string'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
            'dob' => ['nullable', 'string'],
            'create_driver_profile' => ['nullable', 'boolean'],
            'driver_code' => ['nullable', 'string', 'max:50', 'unique:drivers,code'],
            'driver_nic' => ['nullable', 'string', 'max:20'],
            'driver_license_type' => ['nullable', 'exists:driving_license_types,id'],
            'driver_is_active' => ['nullable', 'boolean'],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*.id' => ['nullable', 'uuid'],
            'payment_methods.*.method_type' => ['required_with:payment_methods', 'string', 'in:cash,bank_transfer,cheque,card,wallet,online,other'],
            'payment_methods.*.label' => ['nullable', 'string', 'max:120'],
            'payment_methods.*.account_holder_name' => ['nullable', 'string', 'max:255'],
            'payment_methods.*.bank_name' => ['nullable', 'string', 'max:255'],
            'payment_methods.*.bank_branch' => ['nullable', 'string', 'max:255'],
            'payment_methods.*.account_number' => ['nullable', 'string', 'max:100'],
            'payment_methods.*.routing_number' => ['nullable', 'string', 'max:100'],
            'payment_methods.*.cheque_payee_name' => ['nullable', 'string', 'max:255'],
            'payment_methods.*.cheque_bank_name' => ['nullable', 'string', 'max:255'],
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
                $user = User::where('email', $email)->first();

                if ($user) {
                    // Check if user already has vehicle owner context
                    $existingContext = UserContext::where('user_id', $user->id)
                        ->where('context_type', 'vehicle_owner')
                        ->where('is_active', true)
                        ->first();

                    if ($existingContext) {
                        $validator->errors()->add(
                            'email',
                            'This email is already registered as a vehicle owner. Please use a different email or contact the administrator.'
                        );
                    }

                    // Also check direct VehicleOwner model for backwards compatibility
                    $existingVehicleOwner = VehicleOwner::where('user_id', $user->id)->first();
                    if ($existingVehicleOwner) {
                        $validator->errors()->add(
                            'email',
                            'This email is already registered as a vehicle owner. Please use a different email or contact the administrator.'
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
            'owner_type_id.required' => 'Vehicle owner type is required.',
            'owner_type_id.exists' => 'Selected vehicle owner type is invalid.',
            'first_name.required' => 'First name is required.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
        ];
    }
}
