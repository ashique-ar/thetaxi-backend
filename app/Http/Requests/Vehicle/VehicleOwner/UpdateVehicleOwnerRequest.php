<?php
// app/Http/Requests/Vehicle/VehicleOwner/UpdateVehicleOwnerRequest.php

namespace App\Http\Requests\Vehicle\VehicleOwner;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleOwnerRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        $vehicleOwnerId = $this->route('vehicleOwner')->id;

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                function ($attribute, $value, $fail) use ($vehicleOwnerId) {
                    $exists = \App\Models\User::where('email', $value)
                        ->whereHas('contexts', function ($q) use ($vehicleOwnerId) {
                            $q->where('context_type', 'vehicle_owner')
                                ->where('is_active', true)
                                // ← just compare the foreign key directly
                                ->where('context_id', '!=', $vehicleOwnerId);
                        })
                        ->exists();

                    if ($exists) {
                        $fail('This email is already registered as a vehicle owner.');
                    }
                },
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'owner_type_id' => ['sometimes', 'required', 'exists:vehicle_owner_types,id'],
            'address' => ['sometimes', 'nullable', 'string'],
            'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'exists:states,id'],
            'city' => ['sometimes', 'nullable', 'string'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'license_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'license_expiry' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'contact_info' => ['sometimes', 'nullable', 'array'],
            'company_id' => ['sometimes', 'nullable', 'exists:companies,id'],
        ];
    }
}
