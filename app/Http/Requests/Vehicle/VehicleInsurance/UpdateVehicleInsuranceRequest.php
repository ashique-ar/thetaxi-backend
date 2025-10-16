<?php
// app/Http/Requests/Vehicle/VehicleInsurance/UpdateVehicleInsuranceRequest.php

namespace App\Http\Requests\Vehicle\VehicleInsurance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleInsuranceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'vehicle_id' => ['sometimes', 'required', 'exists:vehicles,id'],
            'provider_id' => ['sometimes', 'required', 'exists:vehicle_insurance_providers,id'],
            'insurance_type_id' => ['sometimes', 'required', 'exists:vehicle_insurance_types,id'],
            'policy_number' => ['sometimes', 'required', 'string'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],
            // 'premium_amount' => ['sometimes', 'required', 'numeric'],
        ];
    }
}
