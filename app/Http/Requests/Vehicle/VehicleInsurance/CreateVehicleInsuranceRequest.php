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
            'provider_id' => ['nullable', 'exists:vehicle_insurance_providers,id'],
            'insurance_type_id' => ['nullable', 'exists:vehicle_insurance_types,id'],
            'policy_number' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'string', 'in:active,expired,renewed,cancelled'],
            'document_files' => ['nullable', 'array'],
            'document_files.*.path' => ['nullable', 'string'],
            'document_files.*.url' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
            // 'premium_amount' => ['required', 'numeric'],
        ];
    }
}
