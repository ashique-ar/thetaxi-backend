<?php
// app/Http/Requests/Vehicle/VehicleContractType/UpdateVehicleContractTypeRequest.php

namespace App\Http\Requests\Vehicle\VehicleContractType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleContractTypeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
