<?php
// app/Http/Requests/Vehicle/VehicleClass/CreateVehicleClassRequest.php

namespace App\Http\Requests\Vehicle\VehicleClass;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleClassRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
