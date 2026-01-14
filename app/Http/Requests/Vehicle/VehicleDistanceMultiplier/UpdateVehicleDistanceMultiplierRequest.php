<?php
// app/Http/Requests/Vehicle/VehicleDistanceMultiplier/UpdateVehicleDistanceMultiplierRequest.php

namespace App\Http\Requests\Vehicle\VehicleDistanceMultiplier;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleDistanceMultiplierRequest extends FormRequest
{

    public function rules()
    {
        return [
            'min_km' => ['sometimes', 'required', 'integer', 'min:0'],
            'max_km' => ['sometimes', 'required', 'integer', 'gte:min_km'],
            'multiplier' => ['sometimes', 'required', 'numeric'],
        ];
    }
}
