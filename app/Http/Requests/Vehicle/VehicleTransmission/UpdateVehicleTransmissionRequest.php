<?php
// app/Http/Requests/Vehicle/VehicleTransmission/UpdateVehicleTransmissionRequest.php

namespace App\Http\Requests\Vehicle\VehicleTransmission;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleTransmissionRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'name'        => ['sometimes','required','string','max:255'],
            'description' => ['sometimes','nullable','string'],
        ];
    }
}
