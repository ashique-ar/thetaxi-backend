<?php
// app/Http/Requests/Vehicle/VehiclePricingSlabDefinition/CreateVehiclePricingSlabDefinitionRequest.php

namespace App\Http\Requests\Vehicle\VehiclePricingSlabDefinition;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehiclePricingSlabDefinitionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'service_type_id' => ['required', 'exists:service_types,id'],
            'name' => ['required', 'string', 'max:255'],
            'min_days' => ['required', 'integer', 'min:0'],
            'max_days' => ['required', 'integer', 'gte:min_days'],
            'min_hours' => ['required', 'integer', 'min:0'],
            'max_hours' => ['required', 'integer', 'gte:min_hours'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],            
        ];
    }
}
