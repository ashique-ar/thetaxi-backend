<?php
// app/Http/Requests/Vehicle/VehicleMake/CreateVehicleMakeRequest.php

namespace App\Http\Requests\Vehicle\VehicleMake;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleMakeRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
