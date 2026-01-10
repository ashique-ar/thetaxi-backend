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
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_inquiry_only' => ['sometimes', 'boolean'],
            'force_quotation_request' => ['sometimes', 'boolean'],
            'passengers_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'hand_luggages' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'air_conditioning' => ['sometimes', 'boolean'],
            'no_of_doors' => ['sometimes', 'boolean'],
            'refundable_deposit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
