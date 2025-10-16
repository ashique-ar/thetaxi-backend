<?php
// app/Http/Requests/Vehicle/VehiclePricingSlab/UpdateVehiclePricingSlabRequest.php

namespace App\Http\Requests\Vehicle\VehiclePricingSlab;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehiclePricingSlabRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'service_type_id' => ['sometimes', 'required', 'exists:service_types,id'],
            'min_days' => ['sometimes', 'required', 'integer', 'min:0'],
            'max_days' => ['sometimes', 'required', 'integer', 'gte:min_days'],
            'base_rate' => ['sometimes', 'required', 'numeric'],
            'rate_type' => ['sometimes', 'required', 'string'],
            'extra_rate' => ['sometimes', 'nullable', 'numeric'],
            'region_id' => ['sometimes', 'nullable', 'exists:company_regions,id'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
