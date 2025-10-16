<?php
// app/Http/Requests/Vehicle/VehicleTransmission/CreateVehicleTransmissionRequest.php

namespace App\Http\Requests\Vehicle\VehicleTransmission;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleTransmissionRequest extends FormRequest
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
