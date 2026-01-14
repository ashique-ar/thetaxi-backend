<?php
// app/Http/Requests/Vehicle/VehicleModel/UpdateVehicleModelRequest.php

namespace App\Http\Requests\Vehicle\VehicleModel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleModelRequest extends FormRequest
{

    public function rules()
    {
        return [
            'make_id' => ['sometimes', 'required', 'exists:vehicle_makes,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
