<?php
// app/Http/Requests/Vehicle/VehicleInsuranceType/CreateVehicleInsuranceTypeRequest.php

namespace App\Http\Requests\Vehicle\VehicleInsuranceType;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleInsuranceTypeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
