<?php
// app/Http/Requests/Vehicle/VehicleDistanceMultiplier/CreateVehicleDistanceMultiplierRequest.php

namespace App\Http\Requests\Vehicle\VehicleDistanceMultiplier;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleDistanceMultiplierRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'min_km' => ['required', 'integer', 'min:0'],
            'max_km' => ['required', 'integer', 'gte:min_km'],
            'multiplier' => ['required', 'numeric'],
        ];
    }
}
