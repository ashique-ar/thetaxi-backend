<?php
// app/Http/Requests/Vehicle/VehicleClass/UpdateVehicleClassRequest.php

namespace App\Http\Requests\Vehicle\VehicleClass;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleClassRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
