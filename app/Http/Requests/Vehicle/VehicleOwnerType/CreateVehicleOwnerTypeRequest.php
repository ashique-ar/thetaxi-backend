<?php
// app/Http/Requests/Vehicle/VehicleOwnerType/CreateVehicleOwnerTypeRequest.php

namespace App\Http\Requests\Vehicle\VehicleOwnerType;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleOwnerTypeRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
