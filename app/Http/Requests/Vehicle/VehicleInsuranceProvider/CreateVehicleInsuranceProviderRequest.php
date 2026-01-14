<?php
// app/Http/Requests/Vehicle/VehicleInsuranceProvider/CreateVehicleInsuranceProviderRequest.php

namespace App\Http\Requests\Vehicle\VehicleInsuranceProvider;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleInsuranceProviderRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ];
    }
}
