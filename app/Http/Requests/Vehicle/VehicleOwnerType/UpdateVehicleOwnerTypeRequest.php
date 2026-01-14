<?php
// app/Http/Requests/Vehicle/VehicleOwnerType/UpdateVehicleOwnerTypeRequest.php

namespace App\Http\Requests\Vehicle\VehicleOwnerType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleOwnerTypeRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
