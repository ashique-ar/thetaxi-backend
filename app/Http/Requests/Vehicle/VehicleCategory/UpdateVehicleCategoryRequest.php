<?php
// app/Http/Requests\Vehicle/VehicleCategory/UpdateVehicleCategoryRequest.php

namespace App\Http\Requests\Vehicle\VehicleCategory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleCategoryRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
