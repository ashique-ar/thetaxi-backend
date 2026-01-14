<?php
// app/Http/Requests/Vehicle/VehiclePricingSlab/UpdateVehiclePricingSlabRequest.php

namespace App\Http\Requests\Vehicle\VehiclePricingSlabDefinition;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehiclePricingSlabDefinitionRequest extends FormRequest
{

    public function rules()
    {
        return [
            'service_type_id' => ['sometimes', 'exists:service_types,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'min_days' => ['sometimes'],
            'max_days' => ['sometimes'],
            'min_hours' => ['sometimes'],
            'max_hours' => ['sometimes'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
