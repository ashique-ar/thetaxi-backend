<?php
// app/Http/Requests/Vehicle/VehiclePricingCommonRateDefinition/CreateVehiclePricingCommonRateDefinitionRequest.php

namespace App\Http\Requests\Vehicle\VehiclePricingCommonRateDefinition;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehiclePricingCommonRateDefinitionRequest extends FormRequest
{

    public function rules()
    {
        return [
            'service_type_id' => ['nullable', 'exists:service_types,id'],
            'code' => ['nullable'],
            'vehicle_group_id' => ['nullable', 'exists:vehicle_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'common_rate_type' => ['required', 'string', 'in:percentage,fixed_amount,flat_rate,per_hour,per_minute,per_day,per_km'],
            'is_mandatory' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'owner_type' => ['nullable', 'string', 'in:corporate'],
            'owner_id' => ['nullable', 'uuid', 'exists:corporates,id', 'required_with:owner_type'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
