<?php
// app/Http/Requests/Vehicle/VehicleMake/UpdateVehicleMakeRequest.php

namespace App\Http\Requests\Vehicle\VehicleMake;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleMakeRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
