<?php
// app/Http/Requests/Vehicle/VehicleCategory/CreateVehicleCategoryRequest.php

namespace App\Http\Requests\Vehicle\VehicleCategory;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleCategoryRequest extends FormRequest
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
