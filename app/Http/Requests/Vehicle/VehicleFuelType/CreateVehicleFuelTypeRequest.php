<?php
// app/Http/Requests/Vehicle/VehicleFuelType/CreateVehicleFuelTypeRequest.php

namespace App\Http\Requests\Vehicle\VehicleFuelType;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleFuelTypeRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'name'        => ['required','string','max:255'],
            'description' => ['nullable','string'],
        ];
    }
}
