<?php
// app/Http/Requests/Vehicle/VehicleGroup/CreateVehicleGroupRequest.php

namespace App\Http\Requests\Vehicle\VehicleGroup;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleGroupRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'grade_id' => ['required', 'exists:vehicle_grades,id'],
            'make_id' => ['required', 'exists:vehicle_makes,id'],
            'model_id' => ['required', 'exists:vehicle_models,id'],
            'transmission_id' => ['required', 'exists:vehicle_transmissions,id'],
            'fuel_type_id' => ['required', 'exists:vehicle_fuel_types,id'],
            'category_id' => ['required', 'exists:vehicle_categories,id'],
            'class_id' => ['nullable', 'exists:vehicle_classes,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'specs' => ['nullable', 'array'],
            'thumbnail' => ['nullable', 'array'],
            'images' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_inquiry_only' => ['nullable', 'boolean'],
            'force_quotation_request' => ['nullable', 'boolean'],
            'passengers_count' => ['nullable', 'integer', 'min:1'],
            'hand_luggages' => ['nullable', 'integer', 'min:0'],
            'air_conditioning' => ['nullable', 'boolean'],
            'no_of_doors' => ['nullable', 'integer'],
            'refundable_deposit' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
