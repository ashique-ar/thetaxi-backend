<?php
// app/Http/Requests/Vehicle/VehicleInsuranceProvider/UpdateVehicleInsuranceProviderRequest.php

namespace App\Http\Requests\Vehicle\VehicleInsuranceProvider;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleInsuranceProviderRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
