<?php
// app/Http/Requests/Vehicle/VehiclePricingSlab/CreateVehiclePricingSlabRequest.php

namespace App\Http\Requests\Vehicle\VehiclePricingSlab;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehiclePricingSlabRequest extends FormRequest
{

    public function rules()
    {
        return [
            'service_type_id' => ['required', 'exists:service_types,id'],
            'region_id' => ['nullable', 'exists:company_regions,id'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
