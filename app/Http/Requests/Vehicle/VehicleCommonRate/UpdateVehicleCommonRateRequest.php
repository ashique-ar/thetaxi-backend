<?php
// app/Http/Requests/Vehicle/VehicleCommonRate/UpdateVehicleCommonRateRequest.php

namespace App\Http\Requests\Vehicle\VehicleCommonRate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleCommonRateRequest extends FormRequest
{

    public function rules()
    {
        return [
            'service_type_id' => ['sometimes', 'nullable', 'exists:service_types,id'],
            'rate_name' => ['sometimes', 'required', 'string', 'max:255'],
            'rate_value' => ['sometimes', 'required', 'numeric'],
            'rate_type' => ['sometimes', 'required', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
