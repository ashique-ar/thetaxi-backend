<?php
// app/Http/Requests/Vehicle/VehicleGroup/UpdateVehicleGroupRequest.php

namespace App\Http\Requests\Vehicle\VehicleGroup;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleGroupRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'grade_id' => ['sometimes', 'nullable', 'exists:vehicle_grades,id'],
            'make_id' => ['sometimes', 'nullable', 'exists:vehicle_makes,id'],
            'model_id' => ['sometimes', 'nullable', 'exists:vehicle_models,id'],
            'transmission_id' => ['sometimes', 'nullable', 'exists:vehicle_transmissions,id'],
            'fuel_type_id' => ['sometimes', 'nullable', 'exists:vehicle_fuel_types,id'],
            'category_id' => ['sometimes', 'nullable', 'exists:vehicle_categories,id'],
            'class_id' => ['sometimes', 'nullable', 'exists:vehicle_classes,id'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'specs' => ['sometimes', 'nullable', 'array'],
            'thumbnail' => ['sometimes', 'nullable', 'array'],
            'images' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
