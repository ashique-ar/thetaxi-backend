<?php
// app/Http/Requests/Vehicle/VehicleModel/CreateVehicleModelRequest.php

namespace App\Http\Requests\Vehicle\VehicleModel;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleModelRequest extends FormRequest
{

    public function rules()
    {
        return [
            'make_id' => ['required', 'exists:vehicle_makes,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
