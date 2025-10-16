<?php
// app/Http/Requests/Vehicle/VehicleContractType/CreateVehicleContractTypeRequest.php

namespace App\Http\Requests\Vehicle\VehicleContractType;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleContractTypeRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'name'        => ['required','string','max:255'],
            'description' => ['nullable','string'],
        ];
    }
}
