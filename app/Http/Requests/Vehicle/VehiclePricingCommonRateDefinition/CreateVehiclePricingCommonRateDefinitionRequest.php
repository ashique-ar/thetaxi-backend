<?php
// app/Http/Requests/Vehicle/VehiclePricingCommonRateDefinition/CreateVehiclePricingCommonRateDefinitionRequest.php

namespace App\Http\Requests\Vehicle\VehiclePricingCommonRateDefinition;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehiclePricingCommonRateDefinitionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'service_type_id' => ['nullable', 'exists:service_types,id'],
            'vehicle_group_id' => ['nullable', 'exists:vehicle_groups,id'],
            'name' => ['required', 'string', 'max:255', 'unique:vehicle_pricing_common_rate_definitions,name'],
            'description' => ['nullable', 'string'],
            'common_rate_type' => ['required', 'string', 'in:percentage,fixed_amount,per_hour,per_day'],
            'is_mandatory' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
