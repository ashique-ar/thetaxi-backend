<?php
// app/Http/Requests/Vehicle/VehicleFuelType/UpdateVehicleFuelTypeRequest.php

namespace App\Http\Requests\Vehicle\VehicleFuelType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleFuelTypeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
