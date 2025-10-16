<?php
// app/Http/Requests/Vehicle/VehiclePricingSlab/UpdateVehiclePricingSlabRequest.php

namespace App\Http\Requests\Vehicle\VehiclePricingSlabDefinition;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehiclePricingSlabDefinitionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'service_type_id' => ['sometimes', 'required', 'exists:service_types,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'min_days' => ['sometimes', 'required', 'integer', 'min:0'],
            'max_days' => ['sometimes', 'required', 'integer', 'gte:min_days'],
            'min_hours' => ['sometimes', 'required', 'integer', 'min:0'],
            'max_hours' => ['sometimes', 'required', 'integer', 'gte:min_hours'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
