<?php
// app/Http/Requests/Vehicle/VehicleGroup/UpdateVehicleGroupRequest.php

namespace App\Http\Requests\Vehicle\VehicleGroup;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleGroupRequest extends FormRequest
{

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
            'service_seo' => ['sometimes', 'nullable', 'array'],
            'service_seo.*.service_type_id' => ['required', 'uuid', 'distinct', 'exists:service_types,id'],
            'service_seo.*.slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'service_seo.*.title' => ['nullable', 'string', 'max:70'],
            'service_seo.*.meta_description' => ['nullable', 'string', 'max:180'],
            'service_seo.*.target_terms' => ['nullable', 'string', 'max:500'],
            'service_seo.*.intro' => ['nullable', 'string', 'max:5000'],
            'service_seo.*.og_image' => ['nullable', 'string', 'max:2048'],
            'service_seo.*.is_indexable' => ['nullable', 'boolean'],
            'specs' => ['sometimes', 'nullable', 'array'],
            'thumbnail' => ['sometimes', 'nullable', 'array'],
            'images' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_inquiry_only' => ['sometimes', 'boolean'],
            'force_quotation_request' => ['sometimes', 'boolean'],
            'passengers_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'hand_luggages' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'air_conditioning' => ['nullable', 'boolean'],
            'no_of_doors' => ['nullable', 'integer'],
            'refundable_deposit' => ['nullable', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
