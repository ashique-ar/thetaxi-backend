<?php
// app/Http/Requests/Vehicle/VehicleInsurance/CreateVehicleInsuranceRequest.php

namespace App\Http\Requests\Vehicle\VehicleInsurance;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleInsuranceRequest extends FormRequest
{

    public function rules()
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'provider_id' => ['required', 'exists:vehicle_insurance_providers,id'],
            'insurance_type_id' => ['required', 'exists:vehicle_insurance_types,id'],
            'policy_number' => ['required', 'string'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            // 'premium_amount' => ['required', 'numeric'],
        ];
    }
}
