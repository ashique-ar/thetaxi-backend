<?php
// app/Http/Requests/Vehicle/VehicleInsuranceType/UpdateVehicleInsuranceTypeRequest.php

namespace App\Http\Requests\Vehicle\VehicleInsuranceType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleInsuranceTypeRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
