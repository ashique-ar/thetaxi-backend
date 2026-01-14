<?php
// app/Http/Requests/Vehicle/VehicleCommonRate/CreateVehicleCommonRateRequest.php

namespace App\Http\Requests\Vehicle\VehicleCommonRate;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleCommonRateRequest extends FormRequest
{

    public function rules()
    {
        return [
            'service_type_id' => ['nullable', 'exists:service_types,id'],
            'rate_name' => ['required', 'string', 'max:255'],
            'rate_value' => ['required', 'numeric'],
            'rate_type' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
