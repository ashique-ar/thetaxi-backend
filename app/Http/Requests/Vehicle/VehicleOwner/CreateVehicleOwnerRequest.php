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
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'owner_type_id' => ['required', 'exists:vehicle_owner_types,id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'city' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'license_number' => ['nullable', 'string'],
            'license_expiry' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'dob' => ['nullable', 'string'],
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
            'last_name.required' => 'Last name is required.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'phone.required' => 'Phone number is required.',
        ];
    }
}
