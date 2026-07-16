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
            'min_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_minutes' => ['sometimes', 'nullable', 'integer', 'gte:min_minutes'],
            'type' => ['sometimes', 'nullable', 'string', 'in:minutes,hours,days,per_day,per_km,flat_rate'],
            'max_km_per_day' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_km_per_package' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'owner_type' => ['nullable', 'string', 'in:corporate'],
            'owner_id' => ['nullable', 'uuid', 'exists:corporates,id', 'required_with:owner_type'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
