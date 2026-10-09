<?php
// app/Http/Requests/Vehicle/VehicleGroup/CreateVehicleGroupRequest.php

namespace App\Http\Requests\Vehicle\VehicleGroup;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleGroupRequest extends FormRequest
{

    public function rules()
    {
        return [
            'grade_id' => ['nullable', 'exists:vehicle_grades,id'],
            'make_id' => ['nullable', 'exists:vehicle_makes,id'],
            'model_id' => ['nullable', 'exists:vehicle_models,id'],
            'transmission_id' => ['nullable', 'exists:vehicle_transmissions,id'],
            'fuel_type_id' => ['nullable', 'exists:vehicle_fuel_types,id'],
            'category_id' => ['nullable', 'exists:vehicle_categories,id'],
            'class_id' => ['nullable', 'exists:vehicle_classes,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'service_seo' => ['nullable', 'array'],
            'service_seo.*.service_type_id' => ['required', 'uuid', 'distinct', 'exists:service_types,id'],
            'service_seo.*.slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'service_seo.*.title' => ['nullable', 'string', 'max:70'],
            'service_seo.*.meta_description' => ['nullable', 'string', 'max:180'],
            'service_seo.*.target_terms' => ['nullable', 'string', 'max:500'],
            'service_seo.*.intro' => ['nullable', 'string', 'max:5000'],
            'service_seo.*.og_image' => ['nullable', 'string', 'max:2048'],
            'service_seo.*.is_indexable' => ['nullable', 'boolean'],
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
