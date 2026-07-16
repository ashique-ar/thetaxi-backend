<?php
// app/Http/Requests/Vehicle/VehiclePricingSlabDefinition/CreateVehiclePricingSlabDefinitionRequest.php

namespace App\Http\Requests\Vehicle\VehiclePricingSlabDefinition;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehiclePricingSlabDefinitionRequest extends FormRequest
{

    public function rules()
    {
        return [
            'service_type_id' => ['required', 'exists:service_types,id'],
            'name' => ['required', 'string', 'max:255'],
            'min_days' => ['nullable', 'integer', 'min:0'],
            'max_days' => ['nullable', 'integer', 'gte:min_days'],
            'min_hours' => ['nullable', 'integer', 'min:0'],
            'max_hours' => ['nullable', 'integer', 'gte:min_hours'],
            'min_minutes' => ['nullable', 'required_if:type,minutes', 'integer', 'min:0'],
            'max_minutes' => ['nullable', 'required_if:type,minutes', 'integer', 'gte:min_minutes'],
            'type' => ['nullable', 'string', 'in:minutes,hours,days,per_day,per_km,flat_rate'],
            'max_km_per_day' => ['nullable', 'integer', 'min:0'],
            'max_km_per_package' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'owner_type' => ['nullable', 'string', 'in:corporate'],
            'owner_id' => ['nullable', 'uuid', 'exists:corporates,id', 'required_with:owner_type'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
